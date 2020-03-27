<?php

namespace MailHawk\DB;

class Emails extends DB {


	public function get_db_suffix() {
		return 'mh_email_addresses';
	}

	public function get_primary_key() {
		return 'ID';
	}

	public function get_db_version() {
		return '1.0';
	}

	public function get_object_type() {
		return 'email_address';
	}

	/**
	 * Add a activity
	 *
	 * @access  public
	 * @since   2.1
	 *
	 * @param array $data
	 *
	 * @return bool|int
	 */
	public function add( $data = [] ) {

		$args = wp_parse_args(
			$data,
			$this->get_column_defaults()
		);

		if ( ! is_email( $args['email'] ) ) {
			return false;
		}

		if ( $this->exists( $args['email'], 'email' ) ) {
			$record = $this->get_by( 'email', $args['email'] );
			$id     = absint( $record->ID );
			$this->update( $id, $args );

			return $id;
		}

		return $this->insert( $args );
	}

	/**
	 * Whether an item exists within the db.
	 *
	 * @param int    $value
	 * @param string $field
	 *
	 * @return bool
	 */
	public function exists( $value = 0, $field = 'email' ) {
		return parent::exists( $value, $field );
	}

	/**
	 * Get columns and formats
	 *
	 * @access  public
	 * @since   2.1
	 */
	public function get_columns() {
		return array(
			'ID'           => '%d',
			'email'        => '%s',
			'status'       => '%s',
			'last_checked' => '%s',
		);
	}

	/**
	 * Get columns and formats
	 *
	 * @access  public
	 * @since   2.1
	 */
	public function get_column_defaults() {
		return array(
			'ID'           => 0,
			'email'        => '',
			'status'       => 'valid',
			'last_checked' => current_time( 'mysql' ),
		);
	}

	public function create_table() {

		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "CREATE TABLE " . $this->table_name . " (
		ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(50) NOT NULL,
		status varchar(20) NOT NULL,
		last_checked datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY (ID),
		UNIQUE KEY email (email),
		KEY status (status),
		KEY last_checked (last_checked)
		) {$this->get_charset_collate()};";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}
}
