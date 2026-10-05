<?php


defined( 'ABSPATH' ) || exit;

/**
 * Environment methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Environment_Trait {

		/**
		 * Render the environment summary card.
		 *
		 * @param array<string, string|array<int, string>> $environment Environment rows.
		 * @return void
		 */
		private function render_environment_card( array $environment ) {
			$lang_label    = __( 'Languages', 'duplicate-content-addon-for-polylang' );
			$active_label  = __( 'Active Languages', 'duplicate-content-addon-for-polylang' );
			$addons_label  = __( 'Polylang Addons', 'duplicate-content-addon-for-polylang' );
			?>
			<section class="dupcap-card dupcap-environment-card" aria-labelledby="dupcap-environment-title">
				<h2 id="dupcap-environment-title">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<?php esc_html_e( 'Site Setup Overview', 'duplicate-content-addon-for-polylang' ); ?>
				</h2>
				<ul class="dupcap-environment-list">
					<?php foreach ( $environment as $label => $value ) : ?>
						<?php
						$is_langs        = ( $label === $lang_label || $label === $active_label );
						$is_default_lang = ( $label === __( 'Default Language', 'duplicate-content-addon-for-polylang' ) );
						$is_addons       = ( $label === $addons_label );
						$langs           = $is_langs ? array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) : array();
						$icon            = $this->get_environment_row_icon( $label );
						$row_class       = 'dupcap-environment-row' . ( $is_addons ? ' dupcap-environment-row--addons' : '' );
						?>
						<li class="<?php echo esc_attr( $row_class ); ?>">
							<span class="dupcap-environment-row-label">
								<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
								<?php echo esc_html( $label ); ?>
							</span>
							<span class="dupcap-environment-row-value">
								<?php if ( $langs ) : ?>
									<span class="dupcap-lang-tags">
										<?php foreach ( $langs as $lang ) : ?>
											<span class="dupcap-lang-tag">
												<?php echo $this->get_language_flag_html( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<span><?php echo esc_html( $lang ); ?></span>
											</span>
										<?php endforeach; ?>
									</span>
								<?php elseif ( $is_default_lang && ! empty( $value ) ) : ?>
									<span class="dupcap-lang-tags">
										<span class="dupcap-lang-tag">
											<?php echo $this->get_language_flag_html( (string) $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span><?php echo esc_html( $value ); ?></span>
										</span>
									</span>
								<?php elseif ( $is_addons && is_array( $value ) && ! empty( $value ) ) : ?>
									<ul class="dupcap-environment-addon-list">
										<?php foreach ( $value as $addon ) : ?>
											<li><?php echo esc_html( $addon ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<?php echo esc_html( is_scalar( $value ) ? (string) $value : '' ); ?>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
					<?php $this->render_environment_site_health_row(); ?>
				</ul>
			</section>
			<?php
		}

		/**
		 * Link to WordPress Site Health, last in the list after WordPress Version.
		 *
		 * Polylang ships its own Site Health checks, so this is where a user goes
		 * for the checks the Inspector deliberately does not repeat.
		 *
		 * @since 2.3.0
		 *
		 * @return void
		 */
		private function render_environment_site_health_row() {
			if ( ! current_user_can( 'view_site_health_checks' ) ) {
				return;
			}
			?>
			<li class="dupcap-environment-row">
				<span class="dupcap-environment-row-label">
					<span class="dashicons dashicons-heart" aria-hidden="true"></span>
					<?php esc_html_e( 'Site Health', 'duplicate-content-addon-for-polylang' ); ?>
				</span>
				<span class="dupcap-environment-row-value">
					<a
						class="dupcap-environment-row-link"
						href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php esc_html_e( 'Open Site Health', 'duplicate-content-addon-for-polylang' ); ?>
						<span class="dashicons dashicons-external" aria-hidden="true"></span>
					</a>
				</span>
			</li>
			<?php
		}

		/**
		 * Get flag HTML (image or emoji) for a given language code.
		 *
		 * @param string $lang_code Language slug or code.
		 * @return string HTML for the flag.
		 */
		private function get_language_flag_html( $lang_code ) {
			$lang_code = strtoupper( trim( (string) $lang_code ) );
			if ( empty( $lang_code ) ) {
				return '';
			}

			// 1. Polylang flag.
			// is_callable(), not method_exists(): Polylang 3.8 forwards get_language()
			// through PLL_Model::__call(), which method_exists() cannot see — with the
			// old guard no Polylang flag ever rendered on 3.8.
			if ( function_exists( 'PLL' ) && isset( PLL()->model ) && is_callable( array( PLL()->model, 'get_language' ) ) ) {
				try {
					$pll_lang = PLL()->model->get_language( strtolower( $lang_code ) );
					if ( $pll_lang && ! empty( $pll_lang->flag_url ) ) {
						return '<img class="dupcap-lang-flag" src="' . esc_url( $pll_lang->flag_url ) . '" alt="" width="16" height="11" />';
					}
				} catch ( \Throwable $e ) {
					// Fall through to the other flag sources below.
					$pll_lang = null;
				}
			}

			// 2. Fall back to a country flag emoji.
			$lang_to_country = array(
				'EN' => 'GB',
				'US' => 'US',
				'UK' => 'GB',
				'DE' => 'DE',
				'ES' => 'ES',
				'IT' => 'IT',
				'HI' => 'IN',
				'FR' => 'FR',
				'JA' => 'JP',
				'ZH' => 'CN',
				'PT' => 'PT',
				'RU' => 'RU',
				'AR' => 'SA',
				'NL' => 'NL',
				'PL' => 'PL',
				'SV' => 'SE',
				'DA' => 'DK',
				'FI' => 'FI',
				'NO' => 'NO',
				'NB' => 'NO',
				'NN' => 'NO',
				'TR' => 'TR',
				'KO' => 'KR',
				'UK' => 'UA',
				'EL' => 'GR',
				'HE' => 'IL',
				'IW' => 'IL',
				'CS' => 'CZ',
				'RO' => 'RO',
				'HU' => 'HU',
				'TH' => 'TH',
				'VI' => 'VN',
				'ID' => 'ID',
				'MS' => 'MY',
				'FA' => 'IR',
				'BN' => 'BD',
				'TA' => 'IN',
				'TE' => 'IN',
				'MR' => 'IN',
				'GU' => 'IN',
				'KN' => 'IN',
				'ML' => 'IN',
				'PA' => 'IN',
				'UR' => 'PK',
				'BG' => 'BG',
				'HR' => 'HR',
				'SR' => 'RS',
				'SK' => 'SK',
				'SL' => 'SI',
				'LT' => 'LT',
				'LV' => 'LV',
				'ET' => 'EE',
				'CA' => 'ES',
				'EU' => 'ES',
				'GL' => 'ES',
			);

			$country = isset( $lang_to_country[ $lang_code ] ) ? $lang_to_country[ $lang_code ] : $lang_code;
			$emoji   = $this->country_code_to_flag_emoji( $country );

			if ( ! empty( $emoji ) ) {
				return '<span class="dupcap-lang-flag-emoji" aria-hidden="true">' . esc_html( $emoji ) . '</span>';
			}

			return '';
		}

		/**
		 * Convert 2-letter country code to flag emoji.
		 *
		 * @param string $country 2-letter country code.
		 * @return string Flag emoji.
		 */
		private function country_code_to_flag_emoji( $country ) {
			$country = strtoupper( substr( $country, 0, 2 ) );
			if ( strlen( $country ) !== 2 || ! ctype_alpha( $country ) ) {
				return '';
			}
			if ( function_exists( 'mb_chr' ) ) {
				$c1 = mb_chr( 0x1F1E6 + ord( $country[0] ) - ord( 'A' ), 'UTF-8' );
				$c2 = mb_chr( 0x1F1E6 + ord( $country[1] ) - ord( 'A' ), 'UTF-8' );
				if ( false !== $c1 && false !== $c2 ) {
					return $c1 . $c2;
				}
			}
			$bytes1 = array( 0xF0, 0x9F, 0x87, 0xA6 + ord( $country[0] ) - ord( 'A' ) );
			$bytes2 = array( 0xF0, 0x9F, 0x87, 0xA6 + ord( $country[1] ) - ord( 'A' ) );
			return pack( 'C*', ...$bytes1 ) . pack( 'C*', ...$bytes2 );
		}

		/**
		 * Dashicon for an environment row label.
		 *
		 * @param string $label Row label.
		 * @return string
		 */
		private function get_environment_row_icon( $label ) {
			$map = array(
				__( 'Polylang Addons', 'duplicate-content-addon-for-polylang' )  => 'admin-plugins',
				__( 'Translation Plugin', 'duplicate-content-addon-for-polylang' ) => 'translation',
				__( 'Page Builder', 'duplicate-content-addon-for-polylang' )       => 'layout',
				__( 'Builder', 'duplicate-content-addon-for-polylang' )            => 'layout',
				__( 'Theme', 'duplicate-content-addon-for-polylang' )              => 'edit',
				__( 'Languages', 'duplicate-content-addon-for-polylang' )          => 'translation',
				__( 'Active Languages', 'duplicate-content-addon-for-polylang' )   => 'translation',
				__( 'Default Language', 'duplicate-content-addon-for-polylang' )   => 'admin-site-alt3',
				__( 'PHP Version', 'duplicate-content-addon-for-polylang' )        => 'editor-code',
				__( 'WordPress Version', 'duplicate-content-addon-for-polylang' )  => 'wordpress',
			);

			return isset( $map[ $label ] ) ? $map[ $label ] : 'info';
		}

		/**
		 * Get environment summary.
		 *
		 * Delegates to {@see DUPCAP_Dashboard_Adapter::provide_environment()} via the
		 * `dupcap_dashboard_environment` filter so detection logic lives in one place.
		 *
		 * @return array<string, string|array<int, string>>
		 */
		private function get_environment_summary() {
			$environment = apply_filters( 'dupcap_dashboard_environment', array() );

			if ( ! is_array( $environment ) || empty( $environment ) ) {
				$environment = class_exists( 'DUPCAP_Dashboard_Adapter' )
					? DUPCAP_Dashboard_Adapter::provide_environment( array() )
					: array();
			}

			if ( ! is_array( $environment ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $environment as $label => $value ) {
				$key = sanitize_text_field( (string) $label );
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
				} else {
					$sanitized[ $key ] = sanitize_text_field( (string) $value );
				}
			}

			return $sanitized;
		}


}
