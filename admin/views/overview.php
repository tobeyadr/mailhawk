<?php

use MailHawk\Api\Postal\Reporting;
use function MailHawk\mailhawk_is_suspended;

wp_enqueue_script( 'chart-js' );

$days = 14;

$data   = Reporting::query( $days, 'daily' );
$limits = Reporting::limits();

if ( is_wp_error( $limits ) || is_wp_error( $data ) ) {

	$error = is_wp_error( $limits ) ? $limits : $data;

	if ( mailhawk_is_suspended() ) {
		wp_die( "<script>location.reload();</script>" );
	}

	wp_die( $error );
}

/** @var array $limits */
$monthly_send_limit = $limits[ array_search( 'monthly_send_limit', wp_list_pluck( $limits, 'type' ) ) ];

$end_time     = strtotime( $data->last_date );
$graph_data   = array_slice( $data->graph_data, 7, 7 );
$compare_data = array_slice( $data->graph_data, 0, 7 );

$labels = [];

for ( $i = 0; $i < 7; $i ++ ) {
	$labels[] = date_i18n( get_option( 'date_format' ), $end_time );
	$end_time -= DAY_IN_SECONDS;
}

$sent_data                = [];
$bounce_data              = [];
$complaint_data           = [];
$sent_compare_data        = [];
$bounce_compare_data      = [];
$complaint_compare_data   = [];

// Older MTA builds don't report complaints, in which case the graph has no complaint lines instead of a false zero.
$has_complaints = isset( $data->graph_data[0]->complaints );

$total_sent       = 0;
$total_bounced    = 0;
$total_complaints = 0;

$compare_complaints = 0;

/**
 * Bounces as a percentage of the messages sent. null when nothing was sent.
 *
 * @param int $bounces
 * @param int $sent
 *
 * @return float|null
 */
$bounce_rate_of = function ( $bounces, $sent ) {
	return $sent > 0 ? round( $bounces / $sent * 100, 2 ) : null;
};

foreach ( $graph_data as $i => $datum ) {

	$sent    = $datum->outgoing;
	$bounces = $datum->bounces;

	$sent_data[]                = $sent;
	$sent_compare_data[]        = $compare_data[ $i ]->outgoing;
	$bounce_data[]              = $bounces;
	$bounce_compare_data[]      = $compare_data[ $i ]->bounces;

	if ( $has_complaints ) {
		$complaint_data[]         = $datum->complaints;
		$complaint_compare_data[] = $compare_data[ $i ]->complaints;

		$total_complaints         += $datum->complaints;
		$compare_complaints       += $compare_data[ $i ]->complaints;
	}

	$total_sent    += $sent;
	$total_bounced += $bounces;
}

// Bounces are part of what was sent, not on top of it: the MTA counts every message it accepts as sent.
$delivery_rate = $total_sent > 0 ? 100 - $bounce_rate_of( $total_bounced, $total_sent ) : 100;

$config = [
	'labels'                        => array_reverse( $labels ),
	'sent'                          => $sent_data,
	'sent_label'                    => __( 'Sent', 'mailhawk' ),
	'bounces'                       => $bounce_data,
	'bounces_label'                 => __( 'Bounces', 'mailhawk' ),
	'sent_previous_7_days'          => $sent_compare_data,
	'sent_previous_7_days_label'    => __( 'Sent previous 7 days', 'mailhawk' ),
	'bounces_previous_7_days'       => $bounce_compare_data,
	'bounces_previous_7_days_label' => __( 'Bounces previous 7 days', 'mailhawk' ),
	'complaints'                    => $has_complaints ? $complaint_data : null,
	'complaints_label'              => __( 'Complaints', 'mailhawk' ),
	'complaints_previous_7_days'    => $has_complaints ? $complaint_compare_data : null,
	'complaints_previous_7_days_label' => __( 'Complaints previous 7 days', 'mailhawk' ),
];

$compare_sent    = 0;
$compare_bounced = 0;

foreach ( $compare_data as $compare_datum ) {
	$sent    = $compare_datum->outgoing;
	$bounces = $compare_datum->bounces;

	$compare_sent    += $sent;
	$compare_bounced += $bounces;
}

$comparisons = [];

$comparisons['sent']                  = abs( ( $total_sent - $compare_sent ) / ( $total_sent ?: 1 ) ) * 100;
$comparisons['sent_is_up']            = $compare_sent < $total_sent;
$comparisons['bounced']               = abs( ( ( $total_bounced - $compare_bounced ) / ( $total_bounced ?: 1 ) ) * 100 );
$comparisons['bounced_is_down']       = $total_bounced < $compare_bounced;
$comparisons['complaints']            = abs( ( $total_complaints - $compare_complaints ) / ( $total_complaints ?: 1 ) ) * 100;
// No change is not a reason to flag the card red
$comparisons['complaints_is_down']    = $total_complaints <= $compare_complaints;
$comparisons['compare_delivery_rate'] = $compare_sent > 0 ? 100 - $bounce_rate_of( $compare_bounced, $compare_sent ) : 100;
$comparisons['deliveries_compare']    = abs( $delivery_rate - $comparisons['compare_delivery_rate'] );
$comparisons['deliveries_is_up']      = $delivery_rate > $comparisons['compare_delivery_rate'];

// Four cards share the row when the MTA reports complaints, otherwise three
$card_class = $has_complaints ? 'fourth' : 'third';

?>
<div class="mailhawk-content-box report">
    <h2><?php _e( 'Last 7 Days' ); ?></h2>
    <div id="graphdata">
        <canvas id="canvas"></canvas>
    </div>
</div>

<div class="mailhawk-content-box <?php echo $card_class; ?> report">
    <h2><?php _e( 'Sent', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo number_format_i18n( $total_sent ); ?></div>
    <div class="comparison">
        <span class="percentage <?php esc_attr_e( $comparisons['sent_is_up'] ? 'green' : 'red' ); ?>">
            <span class="dashicons dashicons-arrow-<?php echo ( $comparisons['sent_is_up'] ) ? 'up' : 'down' ?>-alt"></span>
			<?php echo number_format_i18n( $comparisons['sent'], 2 ); ?>%
	    </span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<div class="mailhawk-content-box <?php echo $card_class; ?> report">
    <h2><?php _e( 'Bounces', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo number_format_i18n( $total_bounced ); ?></div>
    <div class="comparison">
		<span class="percentage <?php esc_attr_e( $comparisons['bounced_is_down'] ? 'green' : 'red' ); ?>">
			<span class="dashicons dashicons-arrow-<?php echo $comparisons['bounced_is_down'] ? 'down' : 'up' ?>-alt"></span>
		<?php echo number_format_i18n( $comparisons['bounced'], 2 ); ?>%
		</span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<?php if ( $has_complaints ): ?>
<div class="mailhawk-content-box <?php echo $card_class; ?> report">
    <h2><?php _e( 'Complaints', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo number_format_i18n( $total_complaints ); ?></div>
    <div class="comparison">
		<span class="percentage <?php esc_attr_e( $comparisons['complaints_is_down'] ? 'green' : 'red' ); ?>">
			<span class="dashicons dashicons-arrow-<?php echo $comparisons['complaints_is_down'] ? 'down' : 'up' ?>-alt"></span>
		<?php echo number_format_i18n( $comparisons['complaints'], 2 ); ?>%
		</span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="mailhawk-content-box <?php echo $card_class; ?> report">
    <h2><?php _e( 'Delivery Rate', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo number_format_i18n( $delivery_rate, 2 ); ?>%</div>
    <div class="comparison">
		<span class="percentage <?php esc_attr_e( $comparisons['deliveries_is_up'] ? 'green' : 'red' ); ?>">
			<span class="dashicons dashicons-arrow-<?php echo $comparisons['deliveries_is_up'] ? 'up' : 'down' ?>-alt"></span>
		<?php echo number_format_i18n( $comparisons['deliveries_compare'], 2 ); ?>%
		</span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<div class="wp-clearfix"></div>
<div class="mailhawk-content-box report usage">
    <h2><?php _e( 'Usage', 'mailhawk' ); ?></h2>

	<?php

	$usage = $monthly_send_limit->usage;
	$limit = $monthly_send_limit->limit;

	$percent_usage = ceil( ( $usage / $limit ?: 1 ) * 100 );

	?>
    <div class="progress-bar-wrap">
        <div class="progress-bar" style="width:<?php echo $percent_usage ?>%"></div>
    </div>
    <p><?php printf( 'Used: <b>%s</b> / %s', number_format_i18n( $usage ), number_format_i18n( $limit ) ); ?></p>
</div>

<div class="wp-clearfix"></div>

<script>

  var config = <?php echo wp_json_encode( $config ); ?>;

  var graphConfig = {
    type: 'line',
    data: {
      labels: config.labels,
      datasets: [
        {
          label: config.sent_label,
          borderColor: '#68A4DA',
          pointBackgroundColor: '#68A4DA',
          backgroundColor: 'rgba(104,164,218,0.20)',
          data: config.sent,
          fill: true,
          pointRadius: 5,
          pointHoverRadius: 7,
        },
        {
          label: config.sent_previous_7_days_label,
          borderColor: '#68A4DA',
          pointBackgroundColor: '#68A4DA',
          backgroundColor: 'rgba(104,164,218,0.20)',
          data: config.sent_previous_7_days,
          fill: false,
          borderDash: [5, 5],
          pointRadius: 5,
          pointHoverRadius: 7,
        },
        {
          label: config.bounces_label,
          borderColor: '#DA3328',
          pointBackgroundColor: '#DA3328',
          backgroundColor: 'rgba(218,51,40,0.10)',
          data: config.bounces,
          fill: true,
        },
        {
          label: config.bounces_previous_7_days_label,
          borderColor: '#DA3328',
          pointBackgroundColor: '#DA3328',
          backgroundColor: 'rgba(218,51,40,0.10)',
          data: config.bounces_previous_7_days,
          fill: false,
          borderDash: [5, 5],
        },
      ],
    },
    options: {
      responsive: true,
      aspectRatio: 3,
      title: {
        display: false,
        text: 'Chart.js Line Chart',
      },
      tooltips: {
        mode: 'index',
        intersect: true,
      },
      hover: {
        mode: 'nearest',
        intersect: true,
      },
      scales: {
        xAxes: [
          {
            display: true,
          },
        ],
        yAxes: [
          {
            display: true,
            ticks: { beginAtZero: true },
          },
        ],
      },
    },
  }

  // Complaints are only added when the MTA reports them.
  if (config.complaints) {
    graphConfig.data.datasets.push(
      {
        label: config.complaints_label,
        borderColor: '#8E5BD0',
        pointBackgroundColor: '#8E5BD0',
        backgroundColor: 'rgba(142,91,208,0.10)',
        data: config.complaints,
        fill: true,
      },
      {
        label: config.complaints_previous_7_days_label,
        borderColor: '#8E5BD0',
        pointBackgroundColor: '#8E5BD0',
        backgroundColor: 'rgba(142,91,208,0.10)',
        data: config.complaints_previous_7_days,
        fill: false,
        borderDash: [5, 5],
      }
    )
  }

  window.onload = function () {
    var ctx = document.getElementById('canvas').getContext('2d')
    window.myLine = new Chart(ctx, graphConfig)
  }

</script>
