<?php
 class List_Bullet_Positioner extends Positioner { function __construct(Frame_Decorator $frame) { parent::__construct($frame); } function position() { $cb = $this->_frame->get_containing_block(); $x = $cb["x"] - $this->_frame->get_width(); $p = $this->_frame->find_block_parent(); $y = $p->get_current_line_box()->y; $n = $this->_frame->get_next_sibling(); if ( $n ) { $style = $n->get_style(); $line_height = $style->length_in_pt($style->line_height, $style->get_font_size()); $offset = $style->length_in_pt($line_height, $n->get_containing_block("h")) - $this->_frame->get_height(); $y += $offset / 2; } $this->_frame->set_position($x, $y); } } 