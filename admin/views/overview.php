<?php

use MailHawk\Api\Postal\Reporting;
use function MailHawk\get_date_time_format;
use function MailHawk\mailhawk_is_suspended;

wp_enqueue_script( 'chart-js' );

$days = 14;

$data   = Reporting::query( $days, 'daily' );
$limits = Reporting::limits();

if ( is_wp_error( $limits ) || is_wp_error( $data ) ){

    $error = is_wp_error( $limits ) ? $limits : $data;

    if ( mailhawk_is_suspended() ){
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

$sent_data           = [];
$bounce_data         = [];
$sent_compare_data   = [];
$bounce_compare_data = [];

$total_sent    = 0;
$total_bounced = 0;

foreach ( $graph_data as $i => $datum ) {

	$sent    = $datum->outgoing;
	$bounces = $datum->bounces;

	$sent_data[]           = $sent;
	$sent_compare_data[]   = $compare_data[ $i ]->outgoing;
	$bounce_data[]         = $bounces;
	$bounce_compare_data[] = $compare_data[ $i ]->bounces;

	$total_sent    += $sent;
	$total_bounced += $bounces;
}

$delivery_rate = round( ( $total_sent / ( $total_bounced + $total_sent ?: 1 ) ) * 100, 2 );

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

$comparisons['sent']                  = abs( round( ( ( $total_sent - $compare_sent ) / ( $total_sent ?: 1 ) ) * 100, 2 ) );
$comparisons['sent_is_up']            = $compare_sent < $total_sent;
$comparisons['bounced']               = abs( round( ( ( $total_bounced - $compare_bounced ) / ( $total_bounced ?: 1 ) ) * 100, 2 ) );
$comparisons['bounced_is_down']       = $total_bounced < $compare_bounced;
$comparisons['compare_delivery_rate'] = round( ( $compare_sent / ( $compare_bounced + $compare_sent ?: 1 ) ) * 100, 2 );
$comparisons['deliveries_compare']    = abs( $delivery_rate - $comparisons['compare_delivery_rate'] );
$comparisons['deliveries_is_up']      = $delivery_rate > $comparisons['compare_delivery_rate'];

?>
<div class="mailhawk-content-box report">
    <h2><?php _e( 'Last 7 Days' ); ?></h2>
    <div id="graphdata">
        <canvas id="canvas"></canvas>
    </div>
</div>

<div class="mailhawk-content-box third report">
    <h2><?php _e( 'Sent', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo $total_sent; ?></div>
    <div class="comparison">
        <span class="percentage <?php esc_attr_e( $comparisons['sent_is_up'] ? 'green' : 'red' ); ?>">
            <span class="dashicons dashicons-arrow-<?php echo ( $comparisons['sent_is_up'] ) ? 'up' : 'down' ?>-alt"></span>
			<?php echo $comparisons['sent']; ?>%
	    </span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<div class="mailhawk-content-box third report">
    <h2><?php _e( 'Bounces', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo $total_bounced; ?></div>
    <div class="comparison">
		<span class="percentage <?php esc_attr_e( $comparisons['bounced_is_down'] ? 'green' : 'red' ); ?>">
			<span class="dashicons dashicons-arrow-<?php echo $comparisons['bounced_is_down'] ? 'down' : 'up' ?>-alt"></span>
		<?php echo $comparisons['bounced']; ?>%
		</span>
        <div class="vs">
            <span><?php _e( 'vs. Previous 7 Days', 'mailhawk' ); ?></span>
        </div>
    </div>
</div>
<div class="mailhawk-content-box third report">
    <h2><?php _e( 'Delivery Rate', 'mailhawk' ); ?></h2>
    <div class="big-number"><?php echo $delivery_rate; ?>%</div>
    <div class="comparison">
		<span class="percentage <?php esc_attr_e( $comparisons['deliveries_is_up'] ? 'green' : 'red' ); ?>">
			<span class="dashicons dashicons-arrow-<?php echo $comparisons['deliveries_is_up'] ? 'up' : 'down' ?>-alt"></span>
		<?php echo $comparisons['deliveries_compare']; ?>%
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
                    pointHoverRadius: 7
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
                    pointHoverRadius: 7
                },
                {
                    label: config.bounces_label,
                    borderColor: '#DA3328',
                    pointBackgroundColor: '#DA3328',
                    backgroundColor: 'rgba(218,51,40,0.10)',
                    data: config.bounces,
                    fill: true
                },
                {
                    label: config.bounces_previous_7_days_label,
                    borderColor: '#DA3328',
                    pointBackgroundColor: '#DA3328',
                    backgroundColor: 'rgba(218,51,40,0.10)',
                    data: config.bounces_previous_7_days,
                    fill: false,
                    borderDash: [5, 5]
                }
            ]
        },
        options: {
            responsive: true,
            aspectRatio: 3,
            title: {
                display: false,
                text: 'Chart.js Line Chart'
            },
            tooltips: {
                mode: 'index',
                intersect: true,
            },
            hover: {
                mode: 'nearest',
                intersect: true
            },
            scales: {
                xAxes: [{
                    display: true,
                }],
                yAxes: [{
                    display: true,
                }]
            }
        }
    };

    window.onload = function () {
        var ctx = document.getElementById('canvas').getContext('2d');
        window.myLine = new Chart(ctx, graphConfig);
    };

</script>
