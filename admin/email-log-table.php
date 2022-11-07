<?php

namespace MailHawk\Admin;

use MailHawk\Classes\Email_Log_Item;
use MailHawk\Plugin;
use WP_List_Table;
use wpdb;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\get_date_time_format;
use function MailHawk\get_email_status_pretty_name;
use function MailHawk\get_url_var;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table is not loaded automatically so we need to load it in our application
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Email_Log_Table extends WP_List_Table {

	/**
	 * TT_Example_List_Table constructor.
	 *
	 * REQUIRED. Set up a constructor that references the parent constructor. We
	 * use the parent reference to set some default configs.
	 */
	public function __construct() {
		// Set parent defaults.
		parent::__construct( array(
			'singular' => 'email',     // Singular name of the listed records.
			'plural'   => 'emails',    // Plural name of the listed records.
			'ajax'     => false,       // Does this table support ajax?
		) );
	}

	/**
	 * Get a list of columns. The format is:
	 * 'internal-name' => 'Title'
	 *
	 * bulk steps or checkboxes, simply leave the 'cb' entry out of your array.
	 *
	 * @see WP_List_Table::::single_row_columns()
	 * @return array An associative array containing column information.
	 */
	public function get_columns() {
		$columns = array(
			'cb'      => '<input type="checkbox" />', // Render a checkbox instead of text.
			'subject' => _x( 'Subject', 'Column label', 'groundhogg' ),
			'to'      => _x( 'Recipients', 'Column label', 'groundhogg' ),
//			'from'    => _x( 'From', 'Column label', 'groundhogg' ),
//			'content' => _x( 'Content', 'Column label', 'groundhogg' ),
			'status'  => _x( 'Status', 'Column label', 'groundhogg' ),
			'sent'    => _x( 'Sent', 'Column label', 'groundhogg' ),
			//'date_created' => _x( 'Date Created', 'Column label', 'groundhogg' ),
		);

		return apply_filters( 'mailhawk/log/columns', $columns );
	}

	/**
	 * Get a list of sortable columns. The format is:
	 * 'internal-name' => 'orderby'
	 * or
	 * 'internal-name' => array( 'orderby', true )
	 *
	 * @return array An associative array containing all the columns that should be sortable.
	 */
	protected function get_sortable_columns() {

		$sortable_columns = array(
			'status' => array( 'status', false ),
			'sent'   => array( 'date_sent', false ),
		);

		return apply_filters( 'mailhawk/log/sortable_columns', $sortable_columns );
	}

	/**
	 * Get the views for the emails, all, ready, unready, trash
	 *
	 * @return array
	 */
	protected function get_views() {

		$views = [
			[
				'id'    => 'all',
				'name'  => __( 'All' ),
				'query' => [],
			],
			[
				'id'    => 'sent',
				'name'  => __( 'Sent', 'mailhawk' ),
				'query' => [ 'status' => 'sent' ],
			],
			[
				'id'    => 'failed',
				'name'  => __( 'Failed', 'mailhawk' ),
				'query' => [ 'status' => 'failed' ],
			]
		];

		$v = [];

		foreach ( $views as $view ) {

			$count  = Plugin::instance()->log->count( $view['query'] );
			$params = array_merge( [ 'view' => 'log', 'subview' => $view['id'] ], $view['query'] );
			$class  = get_url_var( 'subview' ) === $view['id'] ? 'current' : '';

			$v[] = sprintf( "<a class=\"%s\" href=\"%s\">%s <span class=\"count\">(%s)</span></a>", $class, get_admin_mailhawk_uri( $params ), $view['name'], $count );

		}

		return apply_filters( 'mailhawk/log/views', $v );
	}

	/**
	 * @param        $email Email_Log_Item
	 * @param string $column_name
	 * @param string $primary
	 *
	 * @return string
	 */
	protected function handle_row_actions( $email, $column_name, $primary ) {

		if ( $primary !== $column_name ) {
			return '';
		}

		// Resend
		// Retry
		// Blacklist?
		// Whitelist?
		$actions = [];

		switch ( $email->status ) {

			case 'sent':
			case 'delivered':
				$actions['resend']   = "<a href='" . wp_nonce_url( get_admin_mailhawk_uri( [
						'view' => 'log',
						'id'   => $email->get_id()
					] ), 'retry_email', '_mailhawk_nonce' ) . "'>" . __( 'Resend', 'mailhawk' ) . "</a>";
				$actions['mpreview'] = "<a data-log-id=\"" . $email->get_id() . "\" href='" . esc_url( get_admin_mailhawk_uri( [
						'view'    => 'log',
						'preview' => $email->get_id()
					] ) ) . "'>" . __( 'Preview' ) . "</a>";
				break;
			case 'failed':
			case 'bounced':
			case 'softfail':
				$actions['retry']    = "<a href='" . wp_nonce_url( get_admin_mailhawk_uri( [
						'view' => 'log',
						'id'   => $email->get_id()
					] ), 'retry_email', '_mailhawk_nonce' ) . "'>" . __( 'Retry', 'mailhawk' ) . "</a>";
				$actions['mpreview'] = "<a data-log-id=\"" . $email->get_id() . "\" href='" . esc_url( get_admin_mailhawk_uri( [
						'view'    => 'log',
						'preview' => $email->get_id()
					] ) ) . "'>" . __( 'Details', 'mailhawk' ) . "</a>";
				break;

		}

		return $this->row_actions( apply_filters( 'mailhawk/log/row_actions', $actions, $email, $column_name ) );
	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_to( $email ) {

//		print_r( $email->recipients );

		$links = [];

		foreach ( $email->recipients as $recipient ) {

			if ( ! is_email( $recipient ) ) {
				continue;
			}

			$links[] = sprintf( '<a href="mailto:%1$s">%1$s</a>', $recipient );

		}

		return implode( ', ', $links );
	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_subject( $email ) {
		esc_html_e( $email->subject );
	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_from( $email ) {
		esc_html_e( $email->from_address );
	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_content( $email ) {

	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_status( $email ) {

		switch ( $email->status ):

			case 'sent':
			case 'delivered':

				?>
                <span class="email-sent"><?php echo get_email_status_pretty_name( $email->status ); ?></span>
				<?php

				break;
			case 'failed':
			case 'bounced':
			case 'softfail':

				?>
                <span class="email-failed"><?php echo get_email_status_pretty_name( $email->status ); ?></span>
				<?php

				break;

		endswitch;

	}

	/**
	 * @param $email Email_Log_Item
	 *
	 * @return string|void
	 */
	protected function column_sent( $email ) {

		$lu_time   = mysql2date( 'U', $email->date_sent );
		$cur_time  = (int) current_time( 'timestamp' );
		$time_diff = $lu_time - $cur_time;

		if ( absint( $time_diff ) > 24 * HOUR_IN_SECONDS ) {
			$time = date_i18n( get_date_time_format(), intval( $lu_time ) );
		} else {
			$time = sprintf( "%s ago", human_time_diff( $lu_time, $cur_time ) );
		}

		return '<abbr title="' . date_i18n( DATE_ISO8601, intval( $lu_time ) ) . '">' . $time . '</abbr>';
	}

	/**
	 * For more detailed insight into how columns are handled, take a look at
	 * WP_List_Table::single_row_columns()
	 *
	 * @param object $email       A singular item (one full row's worth of data).
	 * @param string $column_name The name/slug of the column to be processed.
	 *
	 * @return string|void Text or HTML to be placed inside the column <td>.
	 */
	protected function column_default( $email, $column_name ) {
		do_action( 'mailhawk/log/custom_column', $email, $column_name );
	}

	/**
	 * Get value for checkbox column.
	 *
	 * @param object $email A singular item (one full row's worth of data).
	 *
	 * @return string Text to be placed inside the column <td>.
	 */
	protected function column_cb( $email ) {
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			$this->_args['singular'],  // Let's simply repurpose the table's singular label ("movie").
			$email->ID                // The value of the checkbox should be the record's ID.
		);
	}

	/**
	 * Get an associative array ( option_name => option_title ) with the list
	 * of bulk steps available on this table.
	 *
	 * @return array An associative array containing all the bulk steps.
	 */
	protected function get_bulk_actions() {

		switch ( get_url_var( 'status' ) ) {
			case 'sent':
				$actions = [
					'resend' => __( 'Resend', 'mailhawk' ),
				];
				break;
			case 'failed':
				$actions = [
					'retry' => __( 'Retry', 'mailhawk' ),
				];
				break;
			default:
				$actions = [
					'retry'  => __( 'Retry', 'mailhawk' ),
					'resend' => __( 'Resend', 'mailhawk' ),
				];
				break;
		}

		return apply_filters( 'mailhawk/log/bulk_actions', $actions );
	}

	/**
	 * Prepares the list of items for displaying.
	 *
	 * REQUIRED! This is where you prepare your data for display. This method will
	 *
	 * @global wpdb $wpdb
	 * @uses $this->_column_headers
	 * @uses $this->items
	 * @uses $this->get_columns()
	 * @uses $this->get_sortable_columns()
	 * @uses $this->get_pagenum()
	 * @uses $this->set_pagination_args()
	 */
	function prepare_items() {

		$columns  = $this->get_columns();
		$hidden   = array(); // No hidden columns
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page = absint( get_url_var( 'limit', 20 ) );
		$paged    = $this->get_pagenum();
		$offset   = $per_page * ( $paged - 1 );
		$search   = get_url_var( 's' );
		$order    = get_url_var( 'order', 'DESC' );
		$orderby  = get_url_var( 'orderby', 'ID' );

		$where = [];

		if ( $status = sanitize_text_field( get_url_var( 'status' ) ) ) {
			$where[] = [ 'col' => 'status', 'compare' => '=', 'val' => $status ];
		}

		$args = array(
			'where'   => $where,
			'search'  => $search,
			'limit'   => $per_page,
			'offset'  => $offset,
			'order'   => $order,
			'orderby' => $orderby,
		);

		$emails = Plugin::instance()->log->query( $args );
		$total  = Plugin::instance()->log->count( $args );

		$items = [];

		foreach ( $emails as $email ) {
			$items[] = new Email_Log_Item( $email->ID );
		}

		$this->items = $items;

		// Add condition to be sure we don't divide by zero.
		// If $this->per_page is 0, then set total pages to 1.
		$total_pages = $per_page ? ceil( (int) $total / (int) $per_page ) : 1;

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		) );
	}
}
