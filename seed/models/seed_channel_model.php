<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Seed Channel Model class
 *
 * @package         seed_ee_addon
 * @version         1.0
 * @author          Joel Bradbury ~ <joel@squarebit.co.uk>
 * @link            http://squarebit.co.uk/seed
 * @copyright       Copyright (c) 2012, Joel 
 */
class Seed_channel_model extends Seed_model {

	private $errors;
	private $field_settings;

	public $known_fieldtypes = array(	'text',
										'textarea',
										'wygwam',
										'playa',
										'matrix',
										'structure' );

	public $known_options = array(		'status',
										'structure', );

	public $overridden_fieldtypes = array( 'rte' => 'wygwam' );

	public $has_settings = array( 'playa', 'matrix' );

	// --------------------------------------------------------------------
	// METHODS
	// --------------------------------------------------------------------

	/**
	 * Constructor
	 *
	 * @access      public
	 * @return      void
	 */
	function __construct()
	{
		// Call parent constructor
		parent::__construct();

		// Initialize this model
		$this->initialize(
			'seed_channel',
			'seed_id',
			array()
		);
	}

	// --------------------------------------------------------------------

	/**
	 * Installs given table
	 *
	 * @access      public
	 * @return      void
	 */
	public function install()
	{
		// Call parent install
		//parent::install();
	}


	// --------------------------------------------------------------------

	/**
	 * Takes a direct form submission, validates it, then runs
	 *
	 * @access      public
	 * @return      void
	 */
	public function seed()
	{
		// Set some basic states
		$this->errors = array();

		// Get the basics about this
		$channel_id = $this->EE->input->post('seed_channel');
		$seed_count = $this->EE->input->post('seed_count');

		if( !is_numeric( $seed_count ) ) $this->errors( lang('seed_count_not_numeric') );

		// Check the channel_id is valid
		$this->channel = $this->_get_details( $channel_id );
		$this->_get_field_plugins();


		// Check we can continue
		if( !empty( $this->errors ) ) return $this->errors();

		//Collect channel options
		$this->channel_options = array();
		foreach( $this->known_options as $option )
		{
			$input_base = 'seed_option_'.$channel_id.'_'.$option;
			$value = $this->EE->input->post( $input_base );

			if( $value != '' AND !empty( $value ) )	$this->channel_options[ $option ] = $value;
		}

		// Now collect passed field settings
		$this->field_options = array();

		foreach( $this->channel['fields'] as $field_id => $field ) 
		{
			$input_base = 'seed_field_'.$channel_id.'_';
			$populate = $this->EE->input->post( $input_base . $field_id );


			if( $populate == 'always' OR $populate == 'sparse' )
			{
				// We'll be populating this field. Get the settings
				// Title gets special treatment
				$this->field_options[ $field_id ] = $this->_get_field_options( $field_id, $input_base );
				$this->field_options[ $field_id ]['populate'] = $populate;
			}
		}

		// Check we can continue
		if( !empty( $this->errors ) ) return $this->errors;

		// Looks ok, go to generate
		$seed['channel_id'] 		= $channel_id;
		$seed['seed_count']			= $seed_count;
		$seed['field_options'] 		= $this->field_options;
		$seed['channel_options'] 	= $this->channel_options;
		
		$results = $this->_generate( $seed );

		if( !empty( $this->errors ) ) return $this->errors;

		return TRUE;
	}

	private function _get_field_plugins()
	{
		// We use this to first filter down the list of needed fields 
		// so we can get the plugin list lazily 

		$plugin_list = array();

		foreach( $this->channel['fields'] as $field )
		{
			$plugin_list[] = $field['field_type'];
		}

		$plugin_list = array_unique( $plugin_list );

		$this->plugins = $this->get_plugins( $plugin_list );

		// Also get the full list of channel options
		$this->options = $this->get_options( $this->known_options );

		return;
	}


	private function _get_field_options( $field_id, $input_base = '' )
	{
		if( $input_base == '' ) 
		{
			$this->errors[] = lang('seed_error_field_settings_not_passed');
			return array();
		}

		// Get the field_type, the specific settings depend on this
		if( !isset( $this->channel['fields'][$field_id] ) ) 
		{
			$this->errors[] = lang('seed_error_invalid_field_id');
			return array();
		}

		$field = $this->channel['fields'][$field_id];

		if( !isset( $this->plugins[ $field['field_type'] ] ) )
		{
			// This field may be being overridden 
			if( array_key_exists( $field['field_type'], $this->overridden_fieldtypes ) )
			{
				$field['field_type'] = $this->overridden_fieldtypes[ $field['field_type'] ];
			}
			else
			{
				// Default unknown field types to text
				$field['field_type'] = 'text';
			}

		}

		$options = array();

		// Check the required fields were passed
		foreach( $this->plugins[ $field['field_type'] ]['settings'] as $setting )
		{
			// Build the field_name
			$passed_input_name = $input_base . $field_id . '_' . $setting['name'];
			$value = $this->EE->input->post( $passed_input_name );

			if( $setting['required'] === TRUE AND $value == '' )
			{
				$this->errors[] = lang('seed_error_missing_required_value');
			}

			$options[ $setting['name'] ] = $value;
			if( isset( $setting['count'] ) ) $options['count'] = $setting['count'];

		}

		// Pass this over to the seed fieldtype for additional handling if requried
		$extra = $this->EE->seed_plugins->$field['field_type']->handle_post( $this->plugins, $field, $input_base );

		$options['extra'] = $extra;
		$options['field_type'] = $field['field_type'];
		$options['field_name'] = $field['field_name'];


		return $options;
	}


	private function _get_details( $channel_id = 0 )
	{
		$channels = array();

		// --------------------------------------
		// Get channels and searchable fields
		// --------------------------------------

		$results  = $this->EE->db->select('c.channel_id, c.channel_title, f.*')
					->from('channels c')
					->join('channel_fields f', 'c.field_group = f.group_id', 'left')
					->where('c.site_id', '1')
					->where('c.channel_id', $channel_id)
			       	->order_by('c.channel_title', 'asc')
			       	->order_by('f.field_order', 'asc')
					->get()
					->result_array();

		foreach( $results as $row )
		{
			// Remember channel title
			$channels['title'] = $row['channel_title'];

			// Add 'Title' to fields while we're here
			if ( ! isset($channels['fields']))
			{
				$channels['fields'][0] = array('field_label'=>lang('title'), 'field_name'=>'title', 'is_title' => TRUE, 'field_required'=>'y', 'field_maxl' => '100', 'field_type' => 'text' );
			}

			// Add custom fields to this channel
			$channels['fields'][$row['field_id']] = $row;
		}


		if( empty( $channels ) ) return FALSE;

		return $channels;	
	}



	private function _generate( $seed = array() )
	{
		if( empty( $seed ) ) return;

		//$this->EE->load->library('api/Api_channel_entries');
		// We have the seed. Go a head and generate

		$this->EE->load->library('api');
		$this->EE->api->instantiate('channel_entries');
		$this->EE->api->instantiate('channel_fields');


		$data['author_id'] 			= 1;
		$data['entry_date'] 		= $this->EE->localize->now;

		$meta = array();

		$this->EE->api_channel_fields->setup_entry_settings($seed['channel_id'], array());

		// Loop this for as many times as we need to create
		// as many entries from the input
		for( $i = 0; $i < $seed['seed_count']; $i++ )
		{
			foreach( $seed['channel_options'] as $option_name => $option_value )
			{	
				$value = $this->EE->seed_options->$option_name->generate( $option_value );

				if( $value !== FALSE ) $data[ $option_name ] = $value;
			}

			foreach( $seed['field_options'] as $field_id => $field ) 
			{
				// Field_id 0 is the title
				if( $field_id == 0 ) 
				{
					$field_name = 'title';
				}
				else
				{
					$field_name = 'field_id_'.$field_id;
				}

				$field['seed_count'] = $i;
				$field['field_id'] = $field_id;
				$field['channel_id'] = $seed['channel_id'];

				// Pass the generation over to the specific field type
				$data[ $field_name ] = $this->EE->seed_plugins->$field['field_type']->generate( $field );
			}


			if( $this->EE->api_channel_entries->submit_new_entry( $seed['channel_id'], $data ) === FALSE )
			{
				$this->errors = $this->EE->api_channel_entries->get_errors();
				return FALSE;
			}

			$entry_id = $this->EE->api_channel_entries->entry_id;


			// Now wrap up an post save data we need
			foreach( $seed['channel_options'] as $option_name => $option_value )
			{				
				$this->EE->seed_options->$option_name->post_save( $entry_id, $data, $option_value );
			}

			foreach( $seed['field_options'] as $field_id => $field ) 
			{

				$field['seed_count'] = $i;
				$field['field_id'] = $field_id;
				$field['channel_id'] = $seed['channel_id'];

				$this->EE->seed_plugins->$field['field_type']->post_save( $entry_id, $data, $field );				
			}

		}

		return TRUE;
	}


	public function get_field_view( $type = 'text', $channel_id, $field_id, $field, $cell = array() )
	{	
		$is_unknown = FALSE;
		$is_overridden = FALSE;
		$is_cell = FALSE;
		$has_post_save = FALSE;

		if( !empty( $cell ) ) $is_cell = TRUE;

		if( array_key_exists( $type, $this->overridden_fieldtypes ) )
		{
			$type = $this->overridden_fieldtypes[ $type ];
			$is_overridden = TRUE;
		}


		if( !in_array( $type, $this->known_fieldtypes ) )
		{
			$is_unknown = TRUE;
			$type = 'text';
		}

		$settings = array();

		if( in_array( $type, $this->has_settings ) )
		{
			if( !isset( $this->EE->seed_plugins ) ) 
			{
				// Get the settings only if we need them
				$this->channel = $this->_get_details( $channel_id );
				$this->_get_field_plugins();

				$this->EE->load->library('api');
				$this->EE->api->instantiate('channel_entries');
				$this->EE->api->instantiate('channel_fields');

				$this->EE->api_channel_fields->setup_entry_settings($channel_id, array());
			}

			// Get the settings for this fieldtype
			$settings = $this->EE->seed_plugins->$type->get_settings( $field_id );
		}

		$data = array( 'channel_id' 	=> $channel_id,
						'field_id' 		=> $field_id,
						'field' 		=> $field,
						'is_unknown' 	=> $is_unknown,
						'is_overridden' => $is_overridden,
						'settings' 		=> $settings,
						'is_cell'		=> $is_cell,
						'cell'			=> $cell );

		$view = $this->EE->load->view( '../fieldtypes/'.$type.'/options', $data, TRUE);
		
		return $view;

	}




	public function get_option_view( $type = '', $channel_id, $option )
	{	
		if( $type == '' ) return '';

		$data = array( 'channel_id' 	=> $channel_id,
						'option' 		=> $option,);

		$view = $this->EE->load->view( '../options/'.$type.'/view', $data, TRUE);
		
		return $view;

	}


} // End class

/* End of file Seed_project_model.php */