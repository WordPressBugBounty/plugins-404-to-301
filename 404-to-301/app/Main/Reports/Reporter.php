<?php
/**
 * Report assembly + dispatch.
 *
 * Dispatched from cron — pulls fresh stats via {@see Stats::compute()},
 * renders an HTML email body, optionally writes a CSV attachment to a
 * temp file, and hands the package off to `wp_mail()`. On success the
 * `email_reports_last_sent_at` / `email_reports_last_sent_id` markers
 * are advanced so the next tick can compute the "X new 404 errors
 * since last report" line cheaply.
 *
 * Reports with zero new rows are skipped — no point spamming the
 * recipient with an empty digest, and the markers are *not* moved
 * forward so the next tick still has the full picture.
 *
 * @package AIOSEO\FourNotFour\Reports
 */

namespace AIOSEO\FourNotFour\Main\Reports;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Sanitizer;

/**
 * Class Reporter
 *
 * @since 4.0.3
 */
final class Reporter {

	/**
	 * Cron entry point.
	 *
	 * Static so it can be referenced as `array( Reporter::class, 'run' )`
	 * without instantiating — keeps the cron callable resolvable even
	 * if the feature's other singletons haven't been touched yet.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function run(): void {
		( new self() )->dispatch();
	}

	/**
	 * Build and send the report.
	 *
	 * No-op when reports are disabled, the parent's classes aren't
	 * loaded, or the recipient field sanitises to empty.
	 *
	 * @since 4.0.3
	 *
	 * @return bool True when the email was queued, false otherwise.
	 */
	public function dispatch(): bool {
		if ( ! aioseo404To301()->options->reports->enabled ) {
			return false;
		}

		if ( ! class_exists( \AIOSEO\FourNotFour\Models\Log::class ) ) {
			return false;
		}

		$recipients = \AIOSEO\FourNotFour\Utils\Sanitizer::emailList(
			aioseo404To301()->options->reports->recipients
		);

		if ( empty( $recipients ) ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( '' !== $fallback ) {
				$recipients = [ $fallback ];
			}
		}

		if ( empty( $recipients ) ) {
			return false;
		}

		$sinceId = (int) aioseo404To301()->internalOptions->internal->reportsLastSentId;
		$stats   = ( new Stats() )->compute( $sinceId );

		// Don't email an empty digest. Markers stay untouched so the
		// next tick keeps looking at the same window.
		if ( $stats['new_logs'] <= 0 && $sinceId > 0 ) {
			return false;
		}

		$frequency = (string) aioseo404To301()->options->reports->frequency;
		$lastAt    = (string) aioseo404To301()->internalOptions->internal->reportsLastSentAt;

		$subject = $this->subject( $stats, $frequency );
		$body    = $this->body( $stats, $frequency, $lastAt );

		$attachments = [];
		$tempCsv     = '';

		if ( aioseo404To301()->options->reports->attachCsv ) {
			$tempCsv = $this->writeCsv( $sinceId );
			if ( '' !== $tempCsv ) {
				$attachments[] = $tempCsv;
			}
		}

		/**
		 * Filter the report email payload immediately before dispatch.
		 *
		 * @since 4.0.3
		 *
		 * @param array $email   Email payload — recipient, subject,
		 *                       body, headers, attachments.
		 * @param array $stats   Computed stats snapshot.
		 * @param array $context Frequency + last-sent-at marker.
		 */
		$email = (array) apply_filters(
			'404_to_301_email_reports_email',
			[
				'recipient'   => $recipients,
				'subject'     => $subject,
				'body'        => $body,
				'headers'     => [ 'Content-Type: text/html; charset=UTF-8' ],
				'attachments' => $attachments,
			],
			$stats,
			[
				'frequency'    => $frequency,
				'last_sent_at' => $lastAt,
			]
		);

		$sent = wp_mail(
			// `wp_mail()` accepts both shapes (string|array); the filter
			// might still hand us a scalar from older callbacks, so we
			// don't force-cast here.
			$email['recipient'] ?? $recipients,
			(string) ( $email['subject'] ?? $subject ),
			(string) ( $email['body'] ?? $body ),
			(array) ( $email['headers'] ?? [] ),
			(array) ( $email['attachments'] ?? [] )
		);

		// Clean up the temp CSV — `wp_mail()` reads it synchronously,
		// so we can drop it as soon as it returns. Skipping the unlink
		// on failure would leak files into the uploads dir on every
		// tick where SMTP is broken.
		if ( '' !== $tempCsv && file_exists( $tempCsv ) ) {
			wp_delete_file( $tempCsv );
		}

		if ( $sent ) {
			aioseo404To301()->internalOptions->internal->reportsLastSentAt = current_time( 'mysql', true );
			aioseo404To301()->internalOptions->internal->reportsLastSentId = (int) $stats['max_id'];

			/**
			 * Fires after a report email has been queued through `wp_mail()`.
			 *
			 * @since 4.0.3
			 *
			 * @param array $stats Stats snapshot included in the report.
			 * @param array $email Final email payload.
			 */
			do_action( '404_to_301_email_reports_sent', $stats, $email );
		}

		return (bool) $sent;
	}

	/**
	 * Build the email subject.
	 *
	 * @since 4.0.3
	 *
	 * @param array  $stats     Stats snapshot.
	 * @param string $frequency Report cadence.
	 *
	 * @return string
	 */
	private function subject( array $stats, string $frequency ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$new  = (int) $stats['new_logs'];

		switch ( $frequency ) {
			case 'daily':
				/* translators: 1: site name, 2: new 404 count. */
				$tpl = __( '[%1$s] Daily 404 report — %2$d new errors', '404-to-301' );
				break;
			case 'monthly':
				/* translators: 1: site name, 2: new 404 count. */
				$tpl = __( '[%1$s] Monthly 404 report — %2$d new errors', '404-to-301' );
				break;
			case 'weekly':
			default:
				/* translators: 1: site name, 2: new 404 count. */
				$tpl = __( '[%1$s] Weekly 404 report — %2$d new errors', '404-to-301' );
				break;
		}

		return sprintf( $tpl, $site, $new );
	}

	/**
	 * Render the HTML email body.
	 *
	 * Inline styles only — most webmail clients still strip `<style>`
	 * blocks (and most still drop external stylesheets entirely), so
	 * styling every element on the spot is the only reliable option.
	 *
	 * @since 4.0.3
	 *
	 * @param array  $stats     Stats snapshot.
	 * @param string $frequency Report cadence.
	 * @param string $lastAt   Previous dispatch timestamp (MySQL UTC),
	 *                          or empty for the very first report.
	 *
	 * @return string
	 */
	private function body( array $stats, string $frequency, string $lastAt ): string {
		$siteName = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$siteUrl  = (string) home_url( '/' );
		$logsUrl  = (string) admin_url( 'admin.php?page=404-to-301-logs' );

		$sinceLine = $this->sinceLine( $stats, $lastAt );

		$styles = [
			'wrap'   => 'font-family:Helvetica,Arial,sans-serif;color:#1d2327;line-height:1.5;max-width:640px;margin:0 auto;padding:24px;',
			'h1'     => 'font-size:20px;margin:0 0 8px;color:#1d2327;',
			'lede'   => 'margin:0 0 20px;color:#50575e;',
			'stats'  => 'border-collapse:collapse;width:100%;margin:0 0 24px;',
			'th'     => 'text-align:left;font-size:12px;text-transform:uppercase;color:#646970;border-bottom:1px solid #dcdcde;padding:6px 8px;',
			'td'     => 'padding:8px;border-bottom:1px solid #f0f0f1;font-size:14px;',
			'big'    => 'font-size:28px;font-weight:600;color:#1d2327;',
			'h2'     => 'font-size:16px;margin:24px 0 8px;color:#1d2327;',
			'top'    => 'border-collapse:collapse;width:100%;',
			'cta'    => 'display:inline-block;background:#2271b1;color:#fff;padding:10px 18px;border-radius:3px;text-decoration:none;margin:16px 0;',
			'footer' => 'margin-top:32px;font-size:12px;color:#646970;',
			'url'    => 'word-break:break-all;color:#2271b1;text-decoration:none;',
		];

		ob_start();
		?>
		<div style="<?php echo esc_attr( $styles['wrap'] ); ?>">
			<h1 style="<?php echo esc_attr( $styles['h1'] ); ?>"><?php echo esc_html( $this->heading( $frequency ) ); ?></h1>
			<p style="<?php echo esc_attr( $styles['lede'] ); ?>">
				<?php
				printf(
					/* translators: 1: site name, 2: site URL. */
					esc_html__( 'Here is the 404 activity digest for %1$s (%2$s).', '404-to-301' ),
					esc_html( $siteName ),
					esc_html( $siteUrl )
				);
				?>
			</p>

			<?php if ( '' !== $sinceLine ) : ?>
				<p style="<?php echo esc_attr( $styles['lede'] ); ?>"><?php echo esc_html( $sinceLine ); ?></p>
			<?php endif; ?>

			<table style="<?php echo esc_attr( $styles['stats'] ); ?>">
				<tr>
					<th style="<?php echo esc_attr( $styles['th'] ); ?>"><?php esc_html_e( 'New 404 errors', '404-to-301' ); ?></th>
					<th style="<?php echo esc_attr( $styles['th'] ); ?>"><?php esc_html_e( 'New 404 hits', '404-to-301' ); ?></th>
					<th style="<?php echo esc_attr( $styles['th'] ); ?>"><?php esc_html_e( 'Lifetime logs', '404-to-301' ); ?></th>
				</tr>
				<tr>
					<td style="<?php echo esc_attr( $styles['td'] . $styles['big'] ); ?>"><?php echo esc_html( number_format_i18n( (int) $stats['new_logs'] ) ); ?></td>
					<td style="<?php echo esc_attr( $styles['td'] . $styles['big'] ); ?>"><?php echo esc_html( number_format_i18n( (int) $stats['new_hits'] ) ); ?></td>
					<td style="<?php echo esc_attr( $styles['td'] . $styles['big'] ); ?>"><?php echo esc_html( number_format_i18n( (int) $stats['total_logs'] ) ); ?></td>
				</tr>
			</table>

			<?php if ( ! empty( $stats['top'] ) ) : ?>
				<h2 style="<?php echo esc_attr( $styles['h2'] ); ?>"><?php esc_html_e( 'Most-hit 404 URLs', '404-to-301' ); ?></h2>
				<table style="<?php echo esc_attr( $styles['top'] ); ?>">
					<tr>
						<th style="<?php echo esc_attr( $styles['th'] ); ?>"><?php esc_html_e( 'URL', '404-to-301' ); ?></th>
						<th style="<?php echo esc_attr( $styles['th'] ); ?>"><?php esc_html_e( 'Hits', '404-to-301' ); ?></th>
					</tr>
					<?php foreach ( $stats['top'] as $row ) : ?>
						<tr>
							<td style="<?php echo esc_attr( $styles['td'] . $styles['url'] ); ?>"><?php echo esc_html( (string) $row['url'] ); ?></td>
							<td style="<?php echo esc_attr( $styles['td'] ); ?>"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<p>
				<a style="<?php echo esc_attr( $styles['cta'] ); ?>" href="<?php echo esc_url( $logsUrl ); ?>">
					<?php esc_html_e( 'Open the Logs page', '404-to-301' ); ?>
				</a>
			</p>

			<p style="<?php echo esc_attr( $styles['footer'] ); ?>">
				<?php esc_html_e( 'You can change the frequency or unsubscribe from this report in 404 to 301 → Settings → Notifications.', '404-to-301' ); ?>
			</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Heading line per cadence.
	 *
	 * @since 4.0.3
	 *
	 * @param string $frequency Report cadence.
	 *
	 * @return string
	 */
	private function heading( string $frequency ): string {
		switch ( $frequency ) {
			case 'daily':
				return (string) __( 'Daily 404 report', '404-to-301' );
			case 'monthly':
				return (string) __( 'Monthly 404 report', '404-to-301' );
			case 'weekly':
			default:
				return (string) __( 'Weekly 404 report', '404-to-301' );
		}
	}

	/**
	 * "X new 404 errors since last report" line.
	 *
	 * Returns an empty string on the very first report so we don't
	 * print a misleading "since 1970-01-01".
	 *
	 * @since 4.0.3
	 *
	 * @param array  $stats   Stats snapshot.
	 * @param string $lastAt Previous dispatch timestamp (MySQL UTC).
	 *
	 * @return string
	 */
	private function sinceLine( array $stats, string $lastAt ): string {
		$new = (int) $stats['new_logs'];

		if ( '' === $lastAt ) {
			/* translators: %d: new 404 count. */
			$line = sprintf( _n( '%d new 404 error recorded so far.', '%d new 404 errors recorded so far.', $new, '404-to-301' ), $new );

			return $line;
		}

		$human = (string) human_time_diff( (int) strtotime( $lastAt . ' UTC' ), (int) time() );

		return sprintf(
			/* translators: 1: count, 2: human-readable elapsed time. */
			_n( '%1$d new 404 error since the last report (%2$s ago).', '%1$d new 404 errors since the last report (%2$s ago).', $new, '404-to-301' ),
			$new,
			$human
		);
	}

	/**
	 * Drop the standard guard files into the report directory.
	 *
	 * `index.php` covers a host with directory listing enabled; the `.htaccess` denies direct file
	 * access on Apache. Both are best-effort — a failure here doesn't stop the report going out.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $dir Absolute path to the report directory.
	 * @return void
	 */
	private function protectDirectory( string $dir ): void {
		$fs    = aioseo404To301()->core->fs;
		$dir   = trailingslashit( $dir );
		$files = [
			'index.php' => "<?php\n// Silence is golden.",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>",
		];

		foreach ( $files as $name => $contents ) {
			if ( ! $fs->exists( $dir . $name ) ) {
				$fs->putContents( $dir . $name, $contents );
			}
		}
	}

	/**
	 * Write a CSV of the new log rows to a temp file in the uploads dir.
	 *
	 * Returns an empty string on any failure — the caller treats that
	 * as "no attachment" rather than aborting the report.
	 *
	 * The CSV is shaped to mirror the Logs Exporter column order so
	 * both outputs open the same way.
	 *
	 * @since 4.0.3
	 *
	 * @param int $sinceId Highest log id covered by the previous report.
	 *
	 * @return string Absolute path to the temp file, or empty on failure.
	 */
	private function writeCsv( int $sinceId ): string {
		if ( ! class_exists( \AIOSEO\FourNotFour\Models\Log::class ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . '404-to-301-reports';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// The CSV holds visitor IPs and lands under uploads/, so it must not be browsable. It is
		// normally deleted the moment wp_mail() returns, but a fatal mid-send would leave it behind.
		$this->protectDirectory( $dir );

		$file   = trailingslashit( $dir ) . 'report-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.csv';
		$handle = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return '';
		}

		$columns = [
			'id'         => __( 'ID', '404-to-301' ),
			'url'        => __( '404 Path', '404-to-301' ),
			'ref'        => __( 'Referrer', '404-to-301' ),
			'ip'         => __( 'IP Address', '404-to-301' ),
			'ua'         => __( 'User Agent', '404-to-301' ),
			'hits'       => __( 'Hits', '404-to-301' ),
			'status'     => __( 'Status', '404-to-301' ),
			'created_at' => __( 'First Seen', '404-to-301' ),
			'updated_at' => __( 'Last Hit', '404-to-301' ),
		];

		// UTF-8 BOM so Excel for Windows opens accented characters
		// cleanly without a manual encoding pick.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $handle, Sanitizer::csvRow( array_values( $columns ) ) );

		$page = 1;

		while ( true ) {
			$result = \AIOSEO\FourNotFour\Models\Log::paginate(
				[
					'number'  => 500,
					'offset'  => ( $page - 1 ) * 500,
					'orderby' => 'id',
					'order'   => 'ASC',
				]
			);

			$items = (array) ( $result['items'] ?? [] );
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $row ) {
				$id = (int) ( $row->id ?? 0 );
				if ( $id <= $sinceId ) {
					continue;
				}

				fputcsv(
					$handle,
					Sanitizer::csvRow(
						[
							(string) $id,
							(string) ( $row->url ?? '' ),
							(string) ( $row->ref ?? '' ),
							method_exists( $row, 'ip' ) ? (string) $row->ip() : (string) ( $row->ip ?? '' ),
							(string) ( $row->ua ?? '' ),
							(string) ( (int) ( $row->hits ?? 0 ) ),
							(string) ( (int) ( $row->status ?? 0 ) ),
							(string) ( $row->created_at ?? '' ),
							(string) ( $row->updated_at ?? '' )
						]
					)
				);
			}

			if ( count( $items ) < 500 ) {
				break;
			}

			++$page;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $file;
	}
}