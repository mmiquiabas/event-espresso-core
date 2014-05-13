<?php

/**
 *
 * Class which determines what data migration files CAN be run, and compares
 * that list to those which have ALREADY run, and determines if there are any that
 * SHOULD run. Also, takes care of running them upon the admin's request in conjunction
 * with the AJAX code on the data migration admin page
 * 
 * When determining what data migration scripts ought to run, compares
 * the wordpress option with name 'espresso_data_migrations' to all the data migration scripts
 * contained in the appointed folders (includes/core/data_migration_scripts in core,
 * but addons can add their own folder). See EE_Data_Migration_Script_Base.php for the data 
 * migration script naming rules (not just conventions).
 * 
 * When performing the migrations, the ajax code on the client-side repeatedly pings
 * a URL which calls EE_Data_Migration_Manager::migration_step(), which in turn calls the currently-executing
 * data migration script and calls its function also named migration_step(), which migrates a few records
 * over to the new database structure, and returns either: EE_Data_Migration_Manager::status_continue to indicate that
 * it's successfully migrated some data, but has more to do on the subsequent ajax request;  EE_Data_Migration_Manager::status_completed
 * to indicate it succesfully migrate some data, and has nothing left to do; or EE_Data_Migration_Manager::status_fatal_error to indicate
 * an error occurred which means the ajax script should probably stop executing. 
 */
class EE_Data_Migration_Manager{
	
	/**
	 *
	 * @var EE_Registry
	 */
	//protected $EE;
	/**
	 * name of the wordpress option which stores an array of data about
	 */
	const data_migrations_option_name = 'ee_data_migration';
	
	
	const data_migration_script_option_prefix = 'ee_data_migration_script_';
	
	const data_migration_script_mapping_option_prefix = 'ee_dms_map_';
	
	/**
	 * name of the wordpress option which stores the database' current version. IE, the code may be at version 4.2.0,
	 * but as migrations are performed the database will progress from 3.1.35 to 4.1.0 etc.
	 */
	const current_database_state = 'ee_data_migration_current_db_state';
	
	/**
	 * Special status string returned when we're positive there are no more data migration
	 * scripts that can be run.
	 */
	const status_no_more_migration_scripts = 'no_more_migration_scripts';
	/**
	 * string indicating the migration should continue
	 */
	const status_continue = 'status_continue';
	/**
	 * string indicating the migration has completed and should be ended
	 */
	const status_completed = 'status_completed';
	/**
	 * string indicating a fatal error occurred and the data migration should be completedly aborted
	 */
	const status_fatal_error = 'status_fatal_error';
	
	/**
	 * the number of 'items' (usually DB rows) to migrate on each 'step' (ajax request sent
	 * during migration)
	 */
	const step_size = 50;
	/**
	 * Array of information concernign data migrations that have ran in the history 
	 * of this EE installation. Keys should be the name of the version the script upgraded to
	 * @var EE_Data_Migration_Script_Base[]
	 */
	private $_data_migrations_ran =null;
	/**
	 * The last ran script. It's nice to store this somewhere accessible, as its easiest
	 * to know which was the last run by which is the newest wp option; but in most of the code
	 * we just use the local $_data_migration_ran array, which organized the scripts differently
	 * @var EE_Data_Migration_Script_Base
	 */
	private $_last_ran_script = null;
	
	/**
	 * Similarly to _last_ran_script, but this is the last INCOMPLETE migration script.
	 * @var EE_Data_Migration_Script_Base
	 */
	private $_last_ran_incomplete_script = null;
	/**
	 * array where keys are classnames, and values are filepaths of all the known migration scripts
	 * @var array
	 */
	private $_data_migration_class_to_filepath_map;
	/**
	 * the following 4 properties are fully set on construction.
	 * Note: the first two apply to whether to conitnue runnign ALL migration scripts (ie, even though we're finished
	 * one, we may want to start the next one); whereas the last two indicate whether to continue running a single
	 * data migration script
	 * @var array
	 */
	var $stati_that_indicate_to_continue_migrations = array();
	var $stati_that_indicate_to_stop_migrations = array();
	var $stati_that_indicate_to_continue_single_migration_script = array();
	var $stati_that_indicate_to_stop_single_migration_script = array();
	
	/**
     * 	@var EE_Data_Migration_Manager $_instance
	 * 	@access 	private 	
     */
	private static $_instance = NULL;
	
	/**
	 *@singleton method used to instantiate class object
	 *@access public
	 *@return EE_Data_Migratino_Manager instance
	 */	
	public static function instance() {
		// check if class object is instantiated
		if ( self::$_instance === NULL  or ! is_object( self::$_instance ) or ! ( self::$_instance instanceof EE_Data_Migration_Manager )) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}	
	/**
	 * resets the singleton to its brand-new state (but does NOT delete old references to the old singleton. Meaning,
	 * all new usages of the singleton shoul dbe made with CLassname::instance())
	 */
	public static function reset(){
		self::$_instance = NULL;
	}
	
	
	private function __construct(){
		$this->stati_that_indicate_to_continue_migrations = array(
			self::status_continue,
			self::status_completed
		);
		$this->stati_that_indicate_to_stop_migrations = array(
			self::status_fatal_error,
			self::status_no_more_migration_scripts
		);
		$this->stati_that_indicate_to_continue_single_migration_script = array(
			self::status_continue
		);
		$this->stati_that_indicate_to_stop_single_migration_script = array(
			self::status_completed,
			self::status_fatal_error
			//note: status_no_more_migration_scripts doesn't apply
		);
		//make sure we've included the base migration script, because we may need the EE_Data_Migration_Script_Error class
		//to be defined, because right now it doesn't get autoloaded on its own
		EE_Registry::instance()->load_core('Data_Migration_Script_Base');
	}
	
	/**
	 * Deciphers, from an option's name, what plugin and version it relates to (see _save_migrations_ran to see what the option names are like, but generally they're like
	 * 'ee_data_migration_script_Core.4.1.0' in 4.2 or 'ee_data_migration_script_4.1.0' befor ethat).
	 * The option name shouldn't ever be like 'ee_data_migration_script_Core.4.1.0.reg' because it's derived, indirectly, from the data migration's classname,
	 * which should always be like EE_DMS_%s_%d_%d_%d.dms.php (eg EE_DMS_Core_4_1_0.dms.php)
	 * @param type $option_name (see 
	 * @return array where the first item is the plugin slug (eg 'Core','Calendar',etc) and the 2nd is the version of that plugin (eg '4.1.0')
	 */
	private function _get_plugin_slug_and_version_string_from_dms_option_name($option_name){
		$plugin_slug_and_version_string = str_replace(EE_Data_Migration_Manager::data_migration_script_option_prefix, "", $option_name);
		//check if $plugin_slug_and_version_string is like '4.1.0' (4.1-style) or 'Core.4.1.0' (4.2-style)
		$parts = explode(".",$plugin_slug_and_version_string);
		
		if(count($parts) == 4){
			//it's 4.2-style.eg Core.4.1.0
			$plugin_slug = $parts[0];//eg Core
			$version_string = $parts[1].".".$parts[2].".".$parts[3]; //eg 4.1.0
		}else{
			//it's 4.1-style: eg 4.1.0
			$plugin_slug = 'Core';
			$version_string = $plugin_slug_and_version_string;//eg 4.1.0
		}
		return array($plugin_slug,$version_string);
	}
	
	/**
	 * Gets the DMS class from the wordpress option, oterhwise throws an EE_Error if it's not
	 * for a known DMS class. 
	 * @param string $dms_option_name
	 * @param string $dms_option_value (serialized)
	 * @return EE_Data_Migration_Script_Base
	 * @throws EE_Error
	 */
	private function _get_dms_class_from_wp_option($dms_option_name,$dms_option_value){
		$data_migration_data = maybe_unserialize($dms_option_value);
		if(isset($data_migration_data['class']) && class_exists($data_migration_data['class'])){
			$class = new $data_migration_data['class'];
			if($class instanceof EE_Data_Migration_Script_Base){
				$class->instantiate_from_array_of_properties($data_migration_data);
				return $class;
			}else{
				//huh, so its an object but not a data migration script?? that shouldn't happen
				//just leave it as an array (which'll probably just get ignored)
				throw new EE_Error(sprintf(__("Trying to retrieve DMS class from wp option. No DMS by the name '%s' exists", 'event_espresso'),$data_migration_data['class']));
			}
		}else{
			//so the data doesn't specify a class. So it must either be a legacy array of info or some array (which we'll probabl yjust ignore), or a class that no longer exists
			throw new EE_Error(sprintf(__("The wp option  with key '%s' does not represent a DMS", 'event_espresso'),$dms_option_name));
		}
	}
	/**
	 * Gets the array describing what data migrations have run. Also has a side-effect of recording which was the last ran, and which was
	 * the last ran which hasn't finished yet
	 * @return array where each element should be an array of EE_Data_Migration_Script_Base (but also has a few legacy arrays in there - which should probalby be ignored)
	 */
	public function get_data_migrations_ran(){
		if( ! $this->_data_migrations_ran ){
			//setup autoloaders for each of the scripts in there
			$this->get_all_data_migration_scripts_available();
			$data_migrations_options = $this->get_all_migration_script_options();//get_option(EE_Data_Migration_Manager::data_migrations_option_name,get_option('espresso_data_migrations',array()));
			
			$data_migrations_ran = array();
			//convert into data migration script classes where possible
			foreach($data_migrations_options as $data_migration_option){
				list($plugin_slug,$version_string) = $this->_get_plugin_slug_and_version_string_from_dms_option_name($data_migration_option['option_name']);
				
				try{
					$class = $this->_get_dms_class_from_wp_option($data_migration_option['option_name'],$data_migration_option['option_value']);
					$data_migrations_ran[$plugin_slug][$version_string] = $class;
					//ok so far THIS is the 'last-ran-script'... unless we find another on next iteration
					$this->_last_ran_script = $class;
					if( ! $class->is_completed()){
						//sometimes we also like to know which was the last incomplete script (or if there are any at all)
						$this->_last_ran_incomplete_script = $class;
					}
				}catch(EE_Error $e){
					//ok so its not a DMS. We'll just keep it, although other code will need to expect non-DMSs
					$data_migrations_ran[$plugin_slug][$version_string] = maybe_unserialize($data_migration_option['option_value']);
				}
			}
			//so here the array of $data_migrations_ran is actually a mix of classes and a few legacy arrays
			$this->_data_migrations_ran = $data_migrations_ran;
			 if ( ! $this->_data_migrations_ran || ! is_array($this->_data_migrations_ran) ){
				$this->_data_migrations_ran = array();
			}
		}
		return $this->_data_migrations_ran;
	}
	/**
	 * 
	 * @param string $script_name eg 'DMS_Core_4_1_0'
	 * @param string $old_table eg 'wp_events_detail'
	 * @param string $old_pk eg 'wp_esp_posts'
	 * @param string $new_pk eg 12
	 * @return mixed string or int
	 */
	public function get_mapping_new_pk($script_name,$old_table,$old_pk,$new_table){
		$script = EE_Registry::instance()->load_dms($script_name);
		$mapping = $script->get_mapping_new_pk($old_table, $old_pk, $new_table);
		return $mapping;
	}
	
	/**
	 * Gets all the options containing migration scripts that have been run
	 * @param boolean @only_get_one FALSE by default- meaning to get ALL; if set ot TRUE, will only retrieve one
	 * @return array
	 */
	 public function get_all_migration_script_options(){
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE option_name like '".EE_Data_Migration_Manager::data_migration_script_option_prefix."%' ORDER BY option_id DESC",ARRAY_A);
	}
	
	/**
	 * Gets the array of folders which contain data migration scripts. Also adds them to be auto-loaded
	 * @return array where each value is the full folderpath of a folder containing data migration scripts, WITH slashes at the end of the 
	 * folder name.
	 */
	public function get_data_migration_script_folders(){
		return  apply_filters( 'FHEE__EE_Data_Migration_Manager__get_data_migration_script_folders',array(EE_CORE.'data_migration_scripts') );
	}
	
	/**
	 * Gets the version the migration script upgrades to
	 * @param string $migration_script_name eg 'EE_DMS_Core_4_1_0'
	 * @return array, where the first element is the plugin slug (like 'Core','Calendar',etc) and the 2nd element is the script woudl update that plugin's data to
	 * @throws EE_Error
	 */
	public function script_migrates_to_version($migration_script_name){
		$dms_info = $this->parse_dms_classname($migration_script_name);
		return array($dms_info['slug'], $dms_info['major_version'].".".$dms_info['minor_version'].".".$dms_info['micro_version']);
	}
	
	/**
	 * Gets the juicy details out of a dms filename like 'EE_DMS_Core_4_1_0'
	 * @param string $classname
	 * @return array with keys 'slug','major_version','minor_version', and 'micro_version' (the last 3 are ints)
	 * @throws EE_Error
	 */
	public function parse_dms_classname($classname){
		$matches = array();
		preg_match('~EE_DMS_(.*)_([0-9]*)_([0-9]*)_([0-9]*)~',$classname,$matches);
		if( ! $matches || ! (isset($matches[1]) && isset($matches[2]) && isset($matches[3]))){
				throw new EE_Error(sprintf(__("%s is not a valid Data Migration Script. The classname should be like EE_DMS_w_x_y_z, where w is either 'Core' or the slug of an addon and x, y and z are numbers, ", "event_espresso"),$migration_script_name));
		}
		return array('slug'=>$matches[1],'major_version'=>intval($matches[2]),'minor_version'=>intval($matches[3]),'micro_version'=>intval($matches[4]));
	}
	/**
	 * Ensures that the option indicating the current DB version is set. This should only be 
	 * a concern when activating EE for the first time, THEORETICALLY. 
	 * If we detect that we're activating EE4 overtop of EE3.1, then we set the current db state to 3.1.x, otherwise
	 * to 4.1.x. 
	 * @return string of current db state
	 */
	public function ensure_current_database_state_is_set(){
		$espresso_db_core_updates = get_option( 'espresso_db_update', array() );
		$db_state = get_option(EE_Data_Migration_Manager::current_database_state);
		if( ! $db_state ){
			//mark the DB as being in the state as the last version in there.
			//this is done to trigger maintenance mode and do data migration scripts
			//if the admin installed this version of EE over 3.1.x or 4.0.x
			//otherwise, the normal maintenance mode code is fine
			$previous_versions_installed = array_keys($espresso_db_core_updates);
			$previous_version_installed = end($previous_versions_installed);
			if(version_compare('4.1.0', $previous_version_installed)){
				//last installed version was less than 4.1
				//so we want the data migrations to happen. SO, we're going to say the DB is at that state
//				echo "4.1.0 is great erhtan $previous_version_installed! update the option";
				$db_state = array('Core'=>$previous_version_installed);
			}else{
//					echo "4.1.0 is SMALLER than $previous_version_installed";
					$db_state = array('Core'=>EVENT_ESPRESSO_VERSION);
			}
			update_option(EE_Data_Migration_Manager::current_database_state,$db_state);
		}
		//in 4.1, $db_state woudl have only been a simple string like '4.1.0',
		//but in 4.2+ it should be an array with at least key 'Core' and the value of that plugin's
		//db, and possibly other keys for other addons like 'Calendar','Permissions',etc
		if( ! is_array($db_state)){
			$db_state = array('Core'=>$db_state);
			update_option(EE_Data_Migration_Manager::current_database_state,$db_state);
		}
		return $db_state;
	}

	/**
	 * Checks if there are any data migration scripts that ought to be run. If found,
	 * returns the instantiated classes. If none are found (ie, they've all already been run
	 * or they don't apply), returns an empty array
	 * @return EE_Data_Migration_Script_Base[]
	 */
	public function check_for_applicable_data_migration_scripts(){
		//get the option describing what options have already run
		$scripts_ran = $this->get_data_migrations_ran();
		//$scripts_ran = array('4.1.0.core'=>array('monkey'=>null));
		$script_class_and_filespaths_available = $this->get_all_data_migration_scripts_available();
		
		$script_classes_that_should_run = array();
		
		$current_database_state = $this->ensure_current_database_state_is_set();
		//determine which have already been run
		foreach($script_class_and_filespaths_available as $classname => $filepath){
			list($script_converts_plugin_slug,$script_converts_to_version) = $this->script_migrates_to_version($classname);
			//check if this version script is DONE or not; or if it's never been ran
			if(		! $scripts_ran || 
					! isset($scripts_ran[$script_converts_plugin_slug]) ||
					! isset($scripts_ran[$script_converts_plugin_slug][$script_converts_to_version])){
				//we haven't ran this conversion script before
				//now check if it applies... note that we've added an autoloader for it on get_all_data_migration_scripts_available
				$script = new $classname;
				/* @var $script EE_Data_Migration_Script_base */
				$can_migrate = $script->can_migrate_from_version($current_database_state);
				if($can_migrate){
					$script_classes_that_should_run[$classname] = $script;
				}
			} elseif($scripts_ran[$script_converts_plugin_slug][$script_converts_to_version] instanceof EE_Data_Migration_Script_Base){
				//this script has been ran, or at least started
				$script = $scripts_ran[$script_converts_plugin_slug][$script_converts_to_version];
				if( $script->get_status() != self::status_completed){
					//this script is already underway... keep going with it
					$script_classes_that_should_run[$classname] = $script;
				}else{
					//it must have a status that indicates it has finished, so we don't want to try and run it again
				}
			}else{
				//it exists but it's not  a proper data migration script
				//maybe the script got renamed? or was simply removed from EE?
				//either way, its certainly not runnable!
			}
		}
		ksort($script_classes_that_should_run);
		return $script_classes_that_should_run;
	}
	
	/**
	 * Gets the script which is currently being ran, if thereis one. If $include_completed_scripts is set to TRUE
	 * it will return the last ran script even if its complete
	 * @return EE_Data_Migration_Script_Base
	 * @throws EE_Error
	 */
	public function get_last_ran_script($include_completed_scripts = false){
		//make sure we've setup the class properties _last_ran_script and _last_ran_incomplete_script
		if($this->_data_migrations_ran){
			$this->get_data_migrations_ran();
		}
		if($include_completed_scripts){
			return $this->_last_ran_script;
		}else{
			return $this->_last_ran_incomplete_script;
		}
	}
	
	/**
	 * Runs the data migration scripts (well, each request to this method calls one of the
	 * data migration scripts' migration_step() functions). 
	 * @return array, where the first item is one EE_Data_Migration_Script_Base's stati, and the second
	 * item is a string describing what was done
	 */
	public function migration_step(){
		try{
			$currently_executing_script = $this->get_last_ran_script();
			if( ! $currently_executing_script){
				//Find the next script that needs to execute
				$scripts = $this->check_for_applicable_data_migration_scripts();
				if( ! $scripts ){
					//huh, no more scripts to run... apparently we're done!
					//but dont forget to make sure intial data is there
					EE_Registry::instance()->load_helper('Activation');
					//we should be good to allow them to exit maintenance mode now
					EE_Maintenance_Mode::instance()->set_maintenance_level(intval(EE_Maintenance_Mode::level_0_not_in_maintenance));
					EEH_Activation::initialize_db_content();
					//make sure the datetime and ticket total sold are correct
					$this->_save_migrations_ran();
					return array(
						'records_to_migrate'=>1,
						'records_migrated'=>1,
						'status'=>self::status_no_more_migration_scripts,  
						'script'=>__("Data Migration Completed Successfully", "event_espresso"),
						'message'=>  __("All done!", "event_espresso"));
				}
				$currently_executing_script = array_shift($scripts);
				//and add to the array/wp option showing the scripts ran
//				$this->_data_migrations_ran[$this->script_migrates_to_version(get_class($currently_executing_script))] = $currently_executing_script;
				list($plugin_slug,$version) = $this->script_migrates_to_version(get_class($currently_executing_script));
				$this->_data_migrations_ran[$plugin_slug][$version] = $currently_executing_script;
			}
			$current_script_name = get_class($currently_executing_script);
		}catch(Exception $e){
			//an exception occurred while trying to get migration scripts
			
			$message =  sprintf(__("Error Message: %s<br>Stack Trace:%s", "event_espresso"),$e->getMessage(),$e->getTraceAsString());
			//record it on the array of data mgiration scripts ran. This will be overwritten next time we try and try to run data migrations
			//but thats ok-- it's just an FYI to support that we coudln't even run any data migrations
			$this->add_error_to_migrations_ran(sprintf(__("Could not run data migrations because: %s", "event_espresso"),$message));
			return array(
				'records_to_migrate'=>1,
				'records_migrated'=>0,
				'status'=>self::status_fatal_error,
				'script'=>  __("Error loading data migration scripts", "event_espresso"),
				'message'=> $message
			);
		}
		//ok so we definitely have a data migration script
		try{
			//how big of a bite do we want to take? Allow users to easily override via their wp-config
			$step_size = defined('EE_MIGRATION_STEP_SIZE') ? EE_MIGRATION_STEP_SIZE : EE_Data_Migration_Manager::step_size;
			//do what we came to do!
			$currently_executing_script->migration_step($step_size);
			switch($currently_executing_script->get_status()){
				case EE_Data_Migration_Manager::status_continue:
					$response_array = array(
						'records_to_migrate'=>$currently_executing_script->count_records_to_migrate(),
						'records_migrated'=>$currently_executing_script->count_records_migrated(),
						'status'=>EE_Data_Migration_Manager::status_continue,
						'message'=>$currently_executing_script->get_feedback_message(),
						'script'=>$currently_executing_script->pretty_name());
					break;
				case EE_Data_Migration_Manager::status_completed:
					//ok so THAT script has completed
					$this->update_current_database_state_to($this->script_migrates_to_version($current_script_name));
					$response_array =  array(
							'records_to_migrate'=>$currently_executing_script->count_records_to_migrate(),
							'records_migrated'=>$currently_executing_script->count_records_migrated(),
							'status'=> EE_Data_Migration_Manager::status_completed,
							'message'=>$currently_executing_script->get_feedback_message(),
							'script'=> sprintf(__("%s Completed",'event_espresso'),$currently_executing_script->pretty_name())
						);
					//check if there are any more after this one. 
					$scripts_remaining = $this->check_for_applicable_data_migration_scripts();
					if( ! $scripts_remaining ){
						//we should be good to allow them to exit maintenance mode now
						EE_Maintenance_Mode::instance()->set_maintenance_level(intval(EE_Maintenance_Mode::level_0_not_in_maintenance));
						////huh, no more scripts to run... apparently we're done!
						//but dont forget to make sure intial data is there
						EE_Registry::instance()->load_helper('Activation');
						EEH_Activation::initialize_db_content();
						$response_array['status'] = self::status_no_more_migration_scripts;
					}
					break;
				default:
					$response_array = array(
						'records_to_migrate'=>$currently_executing_script->count_records_to_migrate(),
						'records_migrated'=>$currently_executing_script->count_records_migrated(),
						'status'=> $currently_executing_script->get_status(),
						'message'=>  sprintf(__("Minor errors occurred during %s: %s", "event_espresso"), $currently_executing_script->pretty_name(), implode(", ",$currently_executing_script->get_errors())),
						'script'=>$currently_executing_script->pretty_name()
					);
					break;
			}
		}catch(Exception $e){
			//ok so some exception was thrown which killed the data migration script
			//double-check we have a real script
			if($currently_executing_script instanceof EE_Data_Migration_Script_Base){
				$script_name = $currently_executing_script->pretty_name();
				$currently_executing_script->set_borked();
				$currently_executing_script->add_error($e->getMessage());
			}else{
				$script_name = __("Error getting Migration Script", "event_espresso");
			}
			$response_array = array(
				'records_to_migrate'=>1,
				'records_migrated'=>0,
				'status'=>self::status_fatal_error,
				'message'=>  sprintf(__("A fatal error occurred during the migration: %s", "event_espresso"),$e->getMessage()),
				'script'=>$script_name
			);
		}
		$succesful_save = $this->_save_migrations_ran();
		if($succesful_save !== TRUE){
			//ok so the current wp option didn't save. that's tricky, because we'd like to update it
			//and mark it as having a fatal error, but remember- WE CAN'T SAVE THIS WP OPTION!
			//however, if we throw an exception, and return that, then the next request
			//won't have as much info in it, and it may be able to save
			throw new EE_Error(sprintf(__("The error '%s' occurred updating the status of the migration. This is a FATAL ERROR, but the error is preventing the system from remembering that. Please contact event espresso support.", "event_espresso"),$succesful_save));
		}
		return $response_array;
	}
	
	/**
	 * Echo out JSON response to migration script AJAX requests. Takes precautions
	 * to buffer output so that we don't throw junk into our json.
	 * @return array with keys:
	 * 'records_to_migrate' which counts ALL the records for the current migration, and should remain constant. (ie, it's NOT the count of hwo many remain)
	 * 'records_migrated' which also coutns ALL the records which have been migrated (ie, percent_complete = records_migrated/records_to_migrate)
	 * 'status'=>a string, one of EE_Data_migratino_Manager::status_*
	 * 'message'=>a string, containing any message you want to show to the user. We may decide to split this up into errors, notifications, and successes
	 * 'script'=>a pretty name of the script currently running
	 */
	public function response_to_migration_ajax_request(){
//		//start output buffer just to make sure we don't mess up the json
		ob_start();
		try{
			$response = $this->migration_step();
		}catch(Exception $e){
			$response = array(
				'records_to_migrate'=>0,
				'records_migrated'=>0,
				'status'=> EE_Data_Migration_Manager::status_fatal_error,
				'message'=> sprintf(__("Unknown fatal error occurred: %s", "event_espresso"),$e->getMessage()),
				'script'=>'Unknown');
			$this->add_error_to_migrations_ran($e->getMessage."; Stack trace:".$e->getTraceAsString());
		}
		$warnings_etc = '';
		$warnings_etc = @ob_get_contents();
		ob_end_clean();
		$response['message'] .=$warnings_etc;
		return $response;
	}
	
	/**
	 * Updates the wordpress option that keeps track of which which EE version the database
	 * is at (ie, the code may be at 4.1.0, but the database is still at 3.1.35)
	 * @param array $slug_and_version where the first item is the plugin slug, the 2nd is a string of the version
	 * @return void
	 */
	public function update_current_database_state_to($slug_and_version = null){
		if( ! $slug_and_version ){
			//no version was provided, assume it should be at the current code version
			
			$slug_and_version = array('Core',espresso_version());
		}
		$current_database_state = get_option(self::current_database_state);
		$current_database_state[$slug_and_version[0]]=$slug_and_version[1];
		update_option(self::current_database_state,$current_database_state);
	}
	
	/**
	 * Gets all the data mgiration scripts available in the core folder and folders
	 * in addons. Has the side effect of adding them for autoloading
	 * @return array keys are expected classnames, values are their filepaths
	 */
	public function get_all_data_migration_scripts_available(){
		if( ! $this->_data_migration_class_to_filepath_map){
			$this->_data_migration_class_to_filepath_map = array();
			foreach($this->get_data_migration_script_folders() as $folder_path){
				if($folder_path[count($folder_path-1)] != DS ){
					$folder_path.= DS;
				}
				$files = glob($folder_path."*.dms.php");
				foreach($files as $file){
					$pos_of_last_slash = strrpos($file,DS);
					$classname = str_replace(".dms.php","", substr($file, $pos_of_last_slash+1));
					$this->_data_migration_class_to_filepath_map[$classname] = $file;
				}

			}
			EEH_Autoloader::register_autoloader($this->_data_migration_class_to_filepath_map);
		}
		return $this->_data_migration_class_to_filepath_map;
	}
	
	
	
	/**
	 * Once we have an addon that works with EE4.1, we will actually want to fetch the PUE slugs
	 * from each addon, and check if they need updating,
	 * @return boolean
	 */
	public function addons_need_updating(){
		return false;
	}
	/**
	 * Adds this error string to the data_migrations_ran array, but we dont necessarily know
	 * where to put it, so we just throw it in there... better than nothing...
	 * @param type $error_message
	 * @throws EE_Error
	 */
	public function add_error_to_migrations_ran($error_message){
		//get last-ran migraiton script
		global $wpdb;
		$last_migration_script_option = $wpdb->get_row("SELECT * FROM ".$wpdb->options." WHERE option_name like '".EE_Data_Migration_Manager::data_migration_script_option_prefix."%' ORDER BY option_id DESC LIMIT 1",ARRAY_A);
		
		$last_ran_migration_script_properties = isset($last_migration_script_option['option_value']) ? maybe_unserialize($last_migration_script_option['option_value']) : null;
		//now, tread lightly because we're here because a FATAL non-catchable error
		//was thrown last time when we were trying to run a data migration script
		//so the fatal error could have happened while getting the mgiration script
		//or doing running it...
		$versions_migrated_to = isset($last_migration_script_option['option_name']) ? str_replace(EE_Data_Migration_Manager::data_migration_script_option_prefix,"",$last_migration_script_option['option_name']) : null;
		
		//check if it THINKS its a data migration script and especially if it's one that HASN'T finished yet
		//because if it has finished, then it obviously couldn't be the cause of this error, right? (because its all done)
		if(isset($last_ran_migration_script_properties['class']) && isset($last_ran_migration_script_properties['_status']) && $last_ran_migration_script_properties['_status'] != self::status_completed){
			//ok then just add this error to its list of errors
			$last_ran_migration_script_properties['_errors'] = $error_message;
			$last_ran_migration_script_properties['_status'] = self::status_fatal_error;
		}else{
			//so we don't even know which script was last running
			//use the data migration error stub, which is designed specifically for this type of thing
			//require the migration script base class file, which also has the error class

			$general_migration_error = new EE_Data_Migration_Script_Error();
			$general_migration_error->add_error($error_message);
			$general_migration_error->set_borked();
			$last_ran_migration_script_properties = $general_migration_error->properties_as_array();
			$versions_migrated_to = 'Unknown.Unknown';
		}
		update_option(self::data_migration_script_option_prefix.$versions_migrated_to,$last_ran_migration_script_properties);
		
	}
	/**
	 * saves what data migrations have ran to the database
	 * @return mixed TRUE if successfully saved migrations ran, string if an error occurred
	 */
	protected function _save_migrations_ran(){
		if($this->_data_migrations_ran == null){
			$this->get_data_migrations_ran();
		}
		//now, we don't want to save actual classes to the DB because that's messy
		$successful_updates = true;
		foreach($this->_data_migrations_ran as $plugin_slug => $migrations_ran_for_plugin){
			foreach($migrations_ran_for_plugin as $version_string => $array_or_migration_obj){
	//			echo "saving migration script to $version_string<br>";
				$plugin_slug_for_use_in_option_name = $plugin_slug.".";
				$old_option_value = get_option(self::data_migration_script_mapping_option_prefix.$plugin_slug_for_use_in_option_name.$version_string);
				if($array_or_migration_obj instanceof EE_Data_Migration_Script_Base){
					$script_array_for_saving = $array_or_migration_obj->properties_as_array();
					if( $old_option_value != $script_array_for_saving){
						$successful_updates = update_option(self::data_migration_script_option_prefix.$plugin_slug_for_use_in_option_name.$version_string,$script_array_for_saving);
					}
				}else{//we don't know what this array-thing is. So just save it as-is
	//				$array_of_migrations[$version_string] = $array_or_migration_obj;
					if($old_option_value != $array_or_migration_obj){
						$successful_updates = update_option(self::data_migration_script_option_prefix.$plugin_slug_for_use_in_option_name.$version_string,$array_or_migration_obj);
					}
				}
	//			if( ! $successful_updates ){
	//					global $wpdb;
	//				return $wpdb->last_error;
	//			}
			}
		}	
		return true;
//		$updated = update_option(self::data_migrations_option_name, $array_of_migrations);
//		if( $updated !== TRUE){
//			global $wpdb;
//			return $wpdb->last_error;
//		}else{
//			return TRUE;
//		}
//				wp_mail("michael@eventespresso.com", time()." price debug info", "updated: $updated, last error: $last_error, byte length of option: ".strlen(serialize($array_of_migrations)));
	}
	
	/**
	 * Takes an array of data migration script properties and re-creates the class from
	 * them. The argument $propertis_array is assumed to have been made by EE_Data_MIgration_Script_Base::properties_as_array()
	 * @param array $properties_array
	 * @return EE_Data_Migration_Script_Base
	 * @throws EE_Error
	 */
	function _instantiate_script_from_properties_array($properties_array){
		if( ! isset($properties_array['class'])){
			throw new EE_Error(sprintf(__("Properties array  has no 'class' properties. Here's what it has: %s", "event_espresso"),implode(",",$properties_array)));
		}
		$class_name = $properties_array['class'];
		if( ! class_exists($class_name)){
			throw new EE_Error(sprintf(__("There is no migration script named %s", "event_espresso"),$class_name));
		}
		$class = new $class_name;
		if( ! $class instanceof EE_Data_Migration_Script_Base){
			throw new EE_Error(sprintf(__("Class '%s' is supposed to be a migration script. Its not, its a '%s'", "event_espresso"),$class_name,get_class($class)));
		}
		$class->instantiate_from_array_of_properties($properties_array);
		return $class;
	}
	
	/**
	 * Gets the classname for the most up-to-date DMS (ie, the one that will finally
	 * leave the DB in a state usable by the current plugin code).
	 * @return string
	 */
	public function get_most_up_to_date_dms(){
		$class_to_filepath_map = $this->get_all_data_migration_scripts_available();
		$most_up_to_date_dms_classname = NULL;
		foreach($class_to_filepath_map as $classname => $filepath){
			if($most_up_to_date_dms_classname === NULL){
				list($plugin_slug,$version_string) = $this->script_migrates_to_version($classname);
//				$details = $this->parse_dms_classname($classname);
				if($plugin_slug == 'Core'){//if it's for core, it wins
					$most_up_to_date_dms_classname = $classname;
				}//if it wasn't for core, we must keep searching for one that is!
				continue;
			}else{
				list($champion_slug,$champion_version) = $this->script_migrates_to_version($most_up_to_date_dms_classname);
				list($contender_slug,$contender_version) = $this->script_migrates_to_version($classname);
				if($contender_slug == 'Core' && version_compare($champion_version, $contender_version, '<')){
					//so the contenders version is higher and its for Core
					$most_up_to_date_dms_classname = $classname;
				}
			}
		}
		return $most_up_to_date_dms_classname;
	}
}
