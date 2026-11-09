<?php

use MailHawk\Api\Postal\Reporting;
use function MailHawk\mailhawk_is_suspended;
use function MailHawk\overview_range_key;
use function MailHawk\overview_ranges;

wp_enqueue_script( 'chart-js' );

// The range is picked with the dropdown in the menu bar
$range_key = overview_range_key();
$range     = overview_ranges()[ $range_key ];
$periods   = $range['periods'];

$data   = Reporting::query( $periods * 2, $range['type'] );
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

$usage = $monthly_send_limit->usage;
$limit = $monthly_send_limit->limit;

$percent_usage = $limit > 0 ? min( 100, ceil( ( $usage / $limit ) * 100 ) ) : 0;
$used_up       = $limit > 0 && $usage >= $limit;

// The MTA resets the usage on the monthly anniversary of the server's creation, at midnight UTC. Older MTA builds
// don't say when, in which case nothing is shown.
$resets_at = ! empty( $monthly_send_limit->resets_at ) ? strtotime( $monthly_send_limit->resets_at ) : false;

// Nudge towards a bigger plan once more than 80% of the month's emails are used
$show_upgrade = $percent_usage > 80;

/**
 * Where the upgrade buttons on the overview lead
 *
 * @param string $url
 */
$upgrade_url = apply_filters( 'mailhawk/upgrade_url', 'https://mailhawk.io/pricing/' );

// The MTA throttles a site that used up its plan: email is turned away with a 429 until it resets, or the plan changes
$is_throttled = $used_up && \MailHawk\mailhawk_is_throttled();

$end_time     = strtotime( $data->last_date );
$graph_data   = array_slice( $data->graph_data, - $periods );
$compare_data = array_slice( $data->graph_data, 0, - $periods );

$labels = [];

for ( $i = 0; $i < $periods; $i ++ ) {
	if ( $range['type'] === 'hourly' ) {
		$labels[] = wp_date( get_option( 'time_format' ), $end_time );
		$end_time -= HOUR_IN_SECONDS;
	} else {
		$labels[] = date_i18n( get_option( 'date_format' ), $end_time );
		$end_time -= DAY_IN_SECONDS;
	}
}

$sent_data      = [];
$bounce_data    = [];
$complaint_data = [];

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

foreach ( $graph_data as $datum ) {

	$sent_data[]   = $datum->outgoing;
	$bounce_data[] = $datum->bounces;

	$total_sent    += $datum->outgoing;
	$total_bounced += $datum->bounces;

	if ( $has_complaints ) {
		$complaint_data[]   = $datum->complaints;
		$total_complaints += $datum->complaints;
	}
}

// Bounces are part of what was sent, not on top of it: the MTA counts every message it accepts as sent.
$delivery_rate = $total_sent > 0 ? 100 - $bounce_rate_of( $total_bounced, $total_sent ) : 100;

$config = [
	'labels'           => array_reverse( $labels ),
	'sent'             => $sent_data,
	'sent_label'       => __( 'Sent', 'mailhawk' ),
	'bounces'          => $bounce_data,
	'bounces_label'    => __( 'Bounces', 'mailhawk' ),
	'complaints'       => $has_complaints ? $complaint_data : null,
	'complaints_label' => __( 'Complaints', 'mailhawk' ),
];

$compare_sent    = 0;
$compare_bounced = 0;

foreach ( $compare_data as $compare_datum ) {
	$compare_sent    += $compare_datum->outgoing;
	$compare_bounced += $compare_datum->bounces;

	if ( $has_complaints ) {
		$compare_complaints += $compare_datum->complaints;
	}
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
<?php if ( $is_throttled ): ?>
    <div class="mailhawk-content-box mailhawk-throttle-notice">
        <h2><?php _e( 'Email is being held back', 'mailhawk' ); ?></h2>
        <p>
			<?php _e( "This site has used all of its emails for the month, so MailHawk is turning email away instead of sending it. The emails it turns away are marked as failed in the log.", 'mailhawk' ); ?>
			<?php if ( $resets_at ): ?>
				<?php printf( __( 'Sending starts again when the limit resets on %s (UTC), or as soon as the plan is upgraded.', 'mailhawk' ), esc_html( date_i18n( get_option( 'date_format' ), $resets_at, true ) ) ); ?>
			<?php else: ?>
				<?php _e( 'Sending starts again when the limit resets, or as soon as the plan is upgraded.', 'mailhawk' ); ?>
			<?php endif; ?>
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"><?php _e( 'Upgrade your plan', 'mailhawk' ); ?></a>
            <a class="button button-secondary" href="<?php echo esc_url( \MailHawk\mailhawk_admin_page( [ 'view' => 'log', 'status' => 'failed' ] ) ); ?>"><?php _e( 'See the failed emails', 'mailhawk' ); ?></a>
        </p>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/groundhogg-promo.php'; ?>
<?php include __DIR__ . '/overview-quarantine.php'; ?>
<div class="mailhawk-content-box report">
    <h2><?php echo esc_html( 'today' === $range_key ? $range['label'] : sprintf( __( 'Last %s', 'mailhawk' ), $range['label'] ) ); ?></h2>
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
            <span><?php echo esc_html( $range['compare'] ); ?></span>
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
            <span><?php echo esc_html( $range['compare'] ); ?></span>
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
            <span><?php echo esc_html( $range['compare'] ); ?></span>
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
            <span><?php echo esc_html( $range['compare'] ); ?></span>
        </div>
    </div>
</div>
<div class="wp-clearfix"></div>
<div class="mailhawk-content-box report usage">
    <h2><?php _e( 'Usage', 'mailhawk' ); ?></h2>

    <div class="progress-bar-wrap">
        <div class="progress-bar" style="width:<?php echo $percent_usage ?>%"></div>
    </div>
    <p>
		<?php printf( 'Used: <b>%s</b> / %s', number_format_i18n( $usage ), number_format_i18n( $limit ) ); ?>
		<?php if ( $resets_at ): ?>
            <span class="usage-resets">&middot; <?php printf( __( 'Resets on %s (UTC)', 'mailhawk' ), esc_html( date_i18n( get_option( 'date_format' ), $resets_at, true ) ) ); ?></span>
		<?php endif; ?>
    </p>
	<?php if ( $show_upgrade ): ?>
        <div class="usage-upgrade">
            <p>
				<?php if ( $used_up ): ?>
                    <b><?php _e( "You've used all of this month's emails.", 'mailhawk' ); ?></b>
					<?php _e( 'Upgrade your plan so your email keeps going out.', 'mailhawk' ); ?>
				<?php else: ?>
                    <b><?php printf( __( "You've used %s%% of this month's emails.", 'mailhawk' ), number_format_i18n( $percent_usage ) ); ?></b>
					<?php _e( 'Upgrade your plan before you run out.', 'mailhawk' ); ?>
				<?php endif; ?>
            </p>
            <a class="button button-primary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"><?php _e( 'Upgrade your plan', 'mailhawk' ); ?></a>
        </div>
	<?php endif; ?>
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
          label: config.bounces_label,
          borderColor: '#DA3328',
          pointBackgroundColor: '#DA3328',
          backgroundColor: 'rgba(218,51,40,0.10)',
          data: config.bounces,
          fill: true,
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
      }
    )
  }

  window.onload = function () {
    var ctx = document.getElementById('canvas').getContext('2d')
    window.myLine = new Chart(ctx, graphConfig)
  }

</script>
