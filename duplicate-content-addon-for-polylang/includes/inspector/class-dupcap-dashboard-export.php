<?php
/**
 * Report builders for the Translation Inspector dashboard.
 *
 * The dashboard no longer offers a download; the AI tools (Abilities API)
 * read the report through get_report_context() and export_report().
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markdown export rendering for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Export_Trait {

	/**
	 * Render the assembled export context as a Markdown report.
	 *
	 * @param array<string, mixed> $context Export context from build_export_context().
	 * @return string
	 */
	private function build_markdown_report( array $context ) {
		$lines        = array();
		$score        = (int) $context['health_score'];
		$score_label  = $this->get_health_label( $score );
		$remaining    = isset( $context['grouped_issues'] ) && is_array( $context['grouped_issues'] ) ? $context['grouped_issues'] : array();
		$split        = $this->split_remaining_groups_by_section( $remaining );
		$content      = $split['content'];
		$site_strings = $split['other'];
		$elementor_groups = isset( $split['elementor'] ) ? $split['elementor'] : array();
		$elementor    = isset( $context['elementor'] ) && is_array( $context['elementor'] ) ? $context['elementor'] : array();
		$dashboard    = class_exists( 'DUPCAP_Admin' ) ? DUPCAP_Admin::page_url() : '';

		$lines[] = '# Translation Inspector Report';
		$lines[] = '';
		$lines[] = sprintf( '**Site:** %s (%s)', $this->md_plain( $context['site_name'] ), $context['site_url'] );
		$lines[] = sprintf( '**Generated:** %s', $this->md_plain( $context['generated_at'] ) );
		$lines[] = sprintf( '**Last scan:** %s', $this->md_plain( $context['last_scanned'] ) );
		if ( $dashboard ) {
			$lines[] = sprintf( '**Open in WordPress:** %s', $dashboard );
		}
		$lines[] = '';

		$lines[] = '## How to read this report';
		$lines[] = '';
		$lines[] = '- **Critical** issues can show the wrong language, a blank page, or a missing translation to visitors.';
		$lines[] = '- **Warnings** are incomplete or copied translations. The site still works, but visitors may see English (or the default language) in places.';
		$lines[] = '- Work **Fix These First**, then **Content**, then **Elementor**, then **Site setup**.';
		$lines[] = '- After you fix items, run **Scan Website** again so the score and this report update.';
		$lines[] = '';

		$lines[] = '## Overview';
		$lines[] = '';
		$lines[] = sprintf(
			'Health score **%1$d%%** (%2$s). That is a weighted mix of content coverage, language setup, and Elementor templates.',
			$score,
			$score_label
		);
		$lines[] = '';
		$lines[] = '| | Count |';
		$lines[] = '|---|---|';
		$lines[] = sprintf( '| Critical issues | %d |', (int) $context['counts']['crit'] );
		$lines[] = sprintf( '| Warnings | %d |', (int) $context['counts']['warn'] );
		$lines[] = sprintf( '| Passed checks | %d |', (int) $context['counts']['pass'] );
		$lines[] = '';
		$lines[] = '### Health by area';
		$lines[] = '';
		$lines[] = '| Area | Score | What it measures |';
		$lines[] = '|---|---|---|';
		$lines[] = sprintf( '| Content | %d%% | Pages, posts, and other content have translations |', (int) $context['health_pillars']['coverage'] );
		$lines[] = sprintf( '| Language setup | %d%% | Languages, URLs, homepage, and menus |', (int) $context['health_pillars']['setup'] );
		$lines[] = sprintf( '| Elementor | %d%% | Headers, footers, templates, and Elementor layouts |', (int) $context['health_pillars']['builder'] );
		$lines[] = '';

		if ( ! empty( $context['environment'] ) ) {
			$lines[] = '### Site environment';
			$lines[] = '';
			$lines[] = '| Setting | Value |';
			$lines[] = '|---|---|';
			foreach ( $context['environment'] as $label => $value ) {
				$display = is_array( $value ) ? implode( ', ', $value ) : $value;
				$lines[] = sprintf( '| %s | %s |', $this->md_cell( $label ), $this->md_cell( $display ) );
			}
			$lines[] = '';
		}

		if ( ! empty( $context['coverage'] ) ) {
			$lines[] = '### Translation coverage by language';
			$lines[] = '';
			$lines[] = 'Coverage is the share of default-language content that already has a matching translation.';
			$lines[] = '';
			$lines[] = '| Language | Coverage |';
			$lines[] = '|---|---|';
			foreach ( $context['coverage'] as $row ) {
				$lines[] = sprintf( '| %s | %d%% |', $this->md_cell( $row['language'] ), (int) $row['coverage'] );
			}
			$lines[] = '';
		}

		$this->append_markdown_next_steps( $lines, $context, $elementor );

		if ( ! empty( $context['priority_issues'] ) ) {
			$lines[] = '## Fix These First (Critical)';
			$lines[] = '';
			$lines[] = 'These problems affect visitors now. Fix them before the warnings below.';
			$lines[] = '';
			foreach ( $context['priority_issues'] as $group ) {
				$this->append_markdown_issue_group( $lines, $group );
			}
		}

		if ( ! empty( $content ) ) {
			$lines[] = '## Content (pages, posts, categories and tags)';
			$lines[] = '';
			$lines[] = 'Issues on translated pages, posts, categories and tags: missing translations, unpublished page/post, copied text, and links that still open the default language.';
			$lines[] = '';
			foreach ( $content as $group ) {
				$this->append_markdown_issue_group( $lines, $group );
			}
		}

		if ( ! empty( $site_strings ) ) {
			$lines[] = '## Site setup';
			$lines[] = '';
			$lines[] = 'Site-wide items: homepage, menus, block navigation, Polylang string translations, and the language switcher.';
			$lines[] = '';
			foreach ( $site_strings as $group ) {
				$this->append_markdown_issue_group( $lines, $group );
			}
		}

		if ( ! empty( $elementor_groups ) || ( ! empty( $elementor['visible'] ) && ! empty( $elementor['buckets'] ) ) ) {
			$lines[] = '## Elementor Translation Insights';
			$lines[] = '';
			$lines[] = 'Problems with Elementor templates and layouts (headers, footers, pages, and widgets). Open the listed item in Elementor to fix it.';
			$lines[] = '';
			foreach ( $elementor_groups as $group ) {
				$this->append_markdown_issue_group( $lines, $group );
			}
			if ( ! empty( $elementor['visible'] ) && ! empty( $elementor['buckets'] ) ) {
				foreach ( $elementor['buckets'] as $bucket ) {
					$this->append_markdown_elementor_bucket( $lines, $bucket );
				}
			}
		}

		if ( ! empty( $context['passed_checks'] ) ) {
			$lines[] = '## What is already working';
			$lines[] = '';
			$lines[] = sprintf( '%d checks passed. No action needed on these.', count( $context['passed_checks'] ) );
			$lines[] = '';
			$lines[] = '| Check | Result |';
			$lines[] = '|---|---|';
			foreach ( $context['passed_checks'] as $check ) {
				$lines[] = sprintf(
					'| %s | %s |',
					$this->md_cell( isset( $check['title'] ) ? $check['title'] : '' ),
					$this->md_cell( isset( $check['message'] ) ? $check['message'] : '' )
				);
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Append a short recommended-order list.
	 *
	 * @param array<int, string>   $lines     Report lines.
	 * @param array<string, mixed> $context   Export context.
	 * @param array<string, mixed> $elementor Elementor insights.
	 * @return void
	 */
	private function append_markdown_next_steps( array &$lines, array $context, array $elementor ) {
		$steps = array();

		if ( ! empty( $context['priority_issues'] ) ) {
			foreach ( $context['priority_issues'] as $group ) {
				$steps[] = sprintf(
					'**%s** — %s',
					$this->md_plain( $this->get_fix_title( $group ) ),
					$this->md_plain( $this->get_export_howto( $group ) )
				);
			}
		}

		if ( ! empty( $elementor['buckets'] ) ) {
			foreach ( $elementor['buckets'] as $bucket ) {
				if ( class_exists( 'DUPCAP_Elementor_Finding' ) && DUPCAP_Elementor_Finding::CRITICAL !== $bucket['severity'] ) {
					continue;
				}
				$steps[] = sprintf(
					'**%s** (%d) — %s',
					$this->md_plain( $bucket['title'] ),
					(int) $bucket['count'],
					$this->md_plain( $this->get_elementor_export_howto( $bucket ) )
				);
			}
		}

		if ( empty( $steps ) ) {
			return;
		}

		$lines[] = '## Recommended order';
		$lines[] = '';
		$i       = 1;
		foreach ( $steps as $step ) {
			$lines[] = sprintf( '%d. %s', $i, $step );
			$i++;
		}
		$lines[] = '';
	}

	/**
	 * Append one issue group with meaning, impact, how to fix, and items.
	 *
	 * @param array<int, string>   $lines Report lines.
	 * @param array<string, mixed> $group Issue group.
	 * @return void
	 */
	private function append_markdown_issue_group( array &$lines, array $group ) {
		$count    = $this->get_group_issue_count( $group );
		$severity = ( 'crit' === $group['severity'] ) ? 'Critical' : 'Warning';

		$lines[] = sprintf(
			'### %s (%d) — %s',
			$this->md_plain( $this->get_group_display_name( $group ) ),
			$count,
			$severity
		);
		$lines[] = '';
		$lines[] = sprintf( '**What this means:** %s', $this->md_plain( $group['message'] ) );
		$lines[] = sprintf( '**Why it matters:** %s', $this->md_plain( $this->get_fix_reason( $group ) ) );
		$lines[] = sprintf( '**How to fix:** %s', $this->md_plain( $this->get_export_howto( $group ) ) );
		if ( ! empty( $group['action_url'] ) && ! empty( $group['action_label'] ) ) {
			$lines[] = sprintf(
				'**Start here:** [%s](%s)',
				$this->md_plain( $group['action_label'] ),
				$group['action_url']
			);
		}
		$lines[] = '';

		$this->append_markdown_group_details( $lines, $group );
	}

	/**
	 * One Markdown row per registered string, languages listed inside the row.
	 *
	 * Mirrors the dashboard's strings table so the row count matches the heading.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, string> $lines   Markdown lines, by reference.
	 * @param array<int, mixed>  $details Detail rows.
	 * @return void
	 */
	private function append_markdown_strings_table( array &$lines, array $details ) {
		$grouped = $this->group_strings_by_item( $details );

		if ( empty( $grouped ) ) {
			return;
		}

		$lines[] = sprintf(
			'| %s | %s | %s |',
			__( 'String', 'duplicate-content-addon-for-polylang' ),
			__( 'Where', 'duplicate-content-addon-for-polylang' ),
			__( 'Missing languages', 'duplicate-content-addon-for-polylang' )
		);
		$lines[] = '|---|---|---|';

		foreach ( $grouped as $row ) {
			$langs = array();
			foreach ( $row['missing'] as $missing ) {
				$langs[] = isset( $missing['name'] ) ? $missing['name'] : '';
			}

			$lines[] = sprintf(
				'| %s | %s | %s |',
				$this->md_cell( isset( $row['title'] ) ? $row['title'] : '' ),
				$this->md_cell( isset( $row['meta'] ) ? $row['meta'] : '' ),
				$this->md_cell( implode( ', ', array_filter( $langs ) ) )
			);
		}

		$lines[] = '';
	}

	/**
	 * Append issue rows for a group.
	 *
	 * @param array<int, string>   $lines Report lines.
	 * @param array<string, mixed> $group Issue group.
	 * @return void
	 */
	private function append_markdown_group_details( array &$lines, array $group ) {
		$details = ( isset( $group['details'] ) && is_array( $group['details'] ) ) ? $group['details'] : array();
		if ( empty( $details ) ) {
			return;
		}

		$key = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';

		/*
		 * The dashboard lists one row per registered string, with its missing
		 * languages inside the row. This export had no such branch, so it dumped
		 * one row per string per language: 120 rows under a heading saying 30.
		 */
		if ( DUPCAP_Issue_Normalizer::is_enabled() && 'strings_missing' === $key ) {
			$this->append_markdown_strings_table( $lines, $details );
			return;
		}

		$grouped_families = array( 'content_missing', 'content_draft', 'term_missing' );

		// Same mismatch for Elementor templates: 24 rows under a heading saying 7.
		if ( DUPCAP_Issue_Normalizer::is_enabled() && 'elementor_templates' === $key && $this->has_content_pending_posts( $details ) ) {
			$grouped_families[] = 'elementor_templates';
		}

		if ( in_array( $key, $grouped_families, true ) ) {
			$is_draft = ( 'content_draft' === $key );
			$grouped  = $this->group_content_pending_by_post( $details, $key );
			if ( empty( $grouped ) ) {
				return;
			}

			$lang_heading = $is_draft
				? __( 'Unpublished languages', 'duplicate-content-addon-for-polylang' )
				: __( 'Missing languages', 'duplicate-content-addon-for-polylang' );

			$lines[] = sprintf( '| %s | %s | %s | %s |', __( 'Title', 'duplicate-content-addon-for-polylang' ), __( 'Type', 'duplicate-content-addon-for-polylang' ), $lang_heading, __( 'Open', 'duplicate-content-addon-for-polylang' ) );
			$lines[] = '|---|---|---|---|';
			foreach ( $grouped as $row ) {
				$langs = array();
				foreach ( $row['missing'] as $missing ) {
					$label = isset( $missing['name'] ) ? $missing['name'] : '';
					if ( $is_draft && ! empty( $missing['status_label'] ) ) {
						$label .= ' (' . $missing['status_label'] . ')';
					}
					$langs[] = $label;
				}
				$open = ! empty( $row['edit_url'] ) ? $row['edit_url'] : '—';
				$lines[] = sprintf(
					'| %s | %s | %s | %s |',
					$this->md_cell( $row['title'] ),
					$this->md_cell( $row['post_type_label'] ),
					$this->md_cell( implode( ', ', $langs ) ),
					$open
				);
			}
			$lines[] = '';
			return;
		}

		if ( 'menu_assignments' === $key ) {
			$lines[] = '| ' . __( 'Menu location', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Missing languages', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Open', 'duplicate-content-addon-for-polylang' ) . ' |';
			$lines[] = '|---|---|---|';
			foreach ( $details as $detail ) {
				$label   = isset( $detail['location_label'] ) ? $detail['location_label'] : '';
				$missing = array();
				if ( ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) ) {
					foreach ( $detail['missing'] as $item ) {
						if ( is_array( $item ) ) {
							$missing[] = isset( $item['name'] ) ? $item['name'] : ( isset( $item['slug'] ) ? $item['slug'] : '' );
						} else {
							$missing[] = (string) $item;
						}
					}
				} elseif ( ! empty( $detail['missing_languages'] ) && is_array( $detail['missing_languages'] ) ) {
					$missing = $detail['missing_languages'];
				}
				$open    = ! empty( $detail['assign_url'] ) ? $detail['assign_url'] : ( ! empty( $detail['action_url'] ) ? $detail['action_url'] : '—' );
				$lines[] = sprintf(
					'| %s | %s | %s |',
					$this->md_cell( $label ),
					$this->md_cell( implode( ', ', $missing ) ),
					$open
				);
			}
			$lines[] = '';
			return;
		}

		if ( 'content_links' === $key ) {
			$lines[] = '| ' . __( 'Where', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'What is wrong', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Open', 'duplicate-content-addon-for-polylang' ) . ' |';
			$lines[] = '|---|---|---|';
			foreach ( $details as $detail ) {
				$where = isset( $detail['label'] ) ? $detail['label'] : '';
				if ( ! empty( $detail['context'] ) ) {
					$where .= ' — ' . $detail['context'];
				}
				$open    = ! empty( $detail['action_url'] ) ? $detail['action_url'] : '—';
				$lines[] = sprintf(
					'| %s | %s | %s |',
					$this->md_cell( $where ),
					$this->md_cell( isset( $detail['message'] ) ? $detail['message'] : '' ),
					$open
				);
			}
			$lines[] = '';
			return;
		}

		if ( 'translated_permalinks' === $key ) {
			foreach ( $details as $detail ) {
				$lines[] = '#### ' . $this->md_plain( isset( $detail['label'] ) ? $detail['label'] : '' );
				$lines[] = '';
				if ( ! empty( $detail['url'] ) ) {
					$lines[] = sprintf( '- **%s:** %s', __( 'Current URL', 'duplicate-content-addon-for-polylang' ), $detail['url'] );
				}
				if ( ! empty( $detail['source_url'] ) ) {
					$lines[] = sprintf( '- **%s:** %s', __( 'Matching URL in another language', 'duplicate-content-addon-for-polylang' ), $detail['source_url'] );
				}
				if ( ! empty( $detail['status_label'] ) ) {
					$lines[] = sprintf( '- **%s:** %s', __( 'Page status', 'duplicate-content-addon-for-polylang' ), $this->md_plain( $detail['status_label'] ) );
				}
				if ( ! empty( $detail['message'] ) ) {
					$lines[] = '- ' . $this->md_plain( $detail['message'] );
				}
				$lines[] = '';
			}
			return;
		}

		$lines[] = '| ' . __( 'Item', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'What is wrong', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Open', 'duplicate-content-addon-for-polylang' ) . ' |';
		$lines[] = '|---|---|---|';
		$is_routing = 'routing_health' === $key;
		foreach ( $details as $detail ) {
			$item = '';
			if ( ! empty( $detail['label'] ) ) {
				$item = $detail['label'];
			} elseif ( ! empty( $detail['title'] ) ) {
				$item = $detail['title'];
			}

			$message = isset( $detail['message'] ) ? $detail['message'] : '';
			if ( '' === $message && ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) ) {
				$names = array();
				foreach ( $detail['missing'] as $m ) {
					$names[] = is_array( $m ) && isset( $m['name'] ) ? $m['name'] : (string) $m;
				}
				$message = implode( ', ', array_filter( $names ) );
			}

			$open = '—';
			if ( $is_routing && ! empty( $detail['url'] ) ) {
				$open = $detail['url'];
			} elseif ( ! empty( $detail['action_url'] ) ) {
				$open = $detail['action_url'];
			} elseif ( ! empty( $detail['edit_url'] ) ) {
				$open = $detail['edit_url'];
			}

			$lines[] = sprintf(
				'| %s | %s | %s |',
				$this->md_cell( $item ),
				$this->md_cell( $message ),
				$open
			);
		}
		$lines[] = '';
	}

	/**
	 * Append one Elementor insight bucket.
	 *
	 * @param array<int, string>   $lines  Report lines.
	 * @param array<string, mixed> $bucket Insight bucket.
	 * @return void
	 */
	private function append_markdown_elementor_bucket( array &$lines, array $bucket ) {
		$severity = ( class_exists( 'DUPCAP_Elementor_Finding' ) && DUPCAP_Elementor_Finding::CRITICAL === $bucket['severity'] )
			? 'Critical'
			: 'Warning';
		$count    = (int) $bucket['count'];
		$examples = ( isset( $bucket['examples'] ) && is_array( $bucket['examples'] ) ) ? $bucket['examples'] : array();

		$lines[] = sprintf(
			'### %s (%d) — %s',
			$this->md_plain( $bucket['title'] ),
			$count,
			$severity
		);
		$lines[] = '';
		$lines[] = sprintf( '**What this means:** %s', $this->md_plain( $bucket['message'] ) );
		$lines[] = sprintf( '**How to fix:** %s', $this->md_plain( $this->get_elementor_export_howto( $bucket ) ) );
		if ( ! empty( $bucket['action_url'] ) && ! empty( $bucket['action_label'] ) ) {
			$lines[] = sprintf(
				'**Start here:** [%s](%s)',
				$this->md_plain( $bucket['action_label'] ),
				$bucket['action_url']
			);
		}
		$lines[] = '';

		if ( empty( $examples ) ) {
			return;
		}

		$lines[] = '| ' . __( 'Language', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Page or template', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Type', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'What is wrong', 'duplicate-content-addon-for-polylang' ) . ' | ' . __( 'Open', 'duplicate-content-addon-for-polylang' ) . ' |';
		$lines[] = '|---|---|---|---|---|';

		foreach ( $examples as $example ) {
			$row = $this->format_export_elementor_example( $example );
			$lines[] = sprintf(
				'| %s | %s | %s | %s | %s |',
				$this->md_cell( $row['language'] ),
				$this->md_cell( $row['title'] ),
				$this->md_cell( $row['type'] ),
				$this->md_cell( $row['detail'] ),
				$row['url'] ? $row['url'] : '—'
			);
		}

		if ( $count > count( $examples ) ) {
			$lines[] = '';
			$lines[] = sprintf(
				'_Showing %1$d of %2$d items from this scan. Open Translation Inspector in WordPress to see the rest._',
				count( $examples ),
				$count
			);
		}
		$lines[] = '';
	}

	/**
	 * Flatten one Elementor example into report columns.
	 *
	 * @param array<string, mixed> $example Example row.
	 * @return array{language:string,title:string,type:string,detail:string,url:string}
	 */
	private function format_export_elementor_example( array $example ) {
		$lang = '';
		if ( ! empty( $example['language'] ) ) {
			$lang = class_exists( 'DUPCAP_Elementor_Fields' )
				? DUPCAP_Elementor_Fields::language_label( (string) $example['language'] )
				: (string) $example['language'];
		}

		$title = '';
		if ( ! empty( $example['title'] ) ) {
			$title = (string) $example['title'];
		} elseif ( ! empty( $example['template_title'] ) ) {
			$title = (string) $example['template_title'];
		}
		$title = $this->md_plain( $title );
		if ( '' === $title ) {
			$id    = isset( $example['template_id'] ) ? (int) $example['template_id'] : 0;
			$title = $id
				? sprintf(
					/* translators: %d: post ID */
					__( 'Untitled (ID %d)', 'duplicate-content-addon-for-polylang' ),
					$id
				)
				: __( 'Untitled', 'duplicate-content-addon-for-polylang' );
		}

		$type = '';
		if ( ! empty( $example['type_display'] ) ) {
			$type = (string) $example['type_display'];
		} elseif ( ! empty( $example['type_label'] ) ) {
			$type = (string) $example['type_label'];
		}

		$detail = '';
		if ( ! empty( $example['detail'] ) ) {
			$detail = (string) $example['detail'];
		} elseif ( ! empty( $example['why'] ) ) {
			$detail = (string) $example['why'];
		} elseif ( ! empty( $example['what'] ) ) {
			$detail = (string) $example['what'];
		}

		if ( '' === $detail && ! empty( $example['summary'] ) ) {
			$detail = (string) $example['summary'];
		}

		if ( ! empty( $example['widget'] ) && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			$widget = DUPCAP_Elementor_Fields::widget_label( (string) $example['widget'] );
			$field  = ! empty( $example['field'] )
				? DUPCAP_Elementor_Fields::field_location_label( (string) $example['field'] )
				: '';
			$place  = $field ? $widget . ' → ' . $field : $widget;
			if ( '' === $detail ) {
				$detail = $place;
			} elseif ( false === strpos( $detail, $widget ) ) {
				$detail = $place . ' — ' . $detail;
			}
		}

		$original    = isset( $example['original_value'] ) ? $this->md_plain( $example['original_value'] ) : '';
		$translated  = isset( $example['translated_value'] ) ? $this->md_plain( $example['translated_value'] ) : '';
		if ( $original && $translated && $original === $translated ) {
			$snippet = function_exists( 'wp_html_excerpt' ) ? wp_html_excerpt( $original, 80, '…' ) : $original;
			$detail .= ' ' . sprintf(
				/* translators: %s: untranslated text */
				__( 'Text is still: “%s”', 'duplicate-content-addon-for-polylang' ),
				$snippet
			);
		} elseif ( $original && '' === $translated ) {
			$detail .= ' ' . __( 'The translated field is empty.', 'duplicate-content-addon-for-polylang' );
		}

		return array(
			'language' => $lang,
			'title'    => $title,
			'type'     => $type,
			'detail'   => $detail,
			'url'      => isset( $example['fix_url'] ) ? (string) $example['fix_url'] : '',
		);
	}

	/**
	 * Plain-language how-to for a dashboard issue group.
	 *
	 * @param array<string, mixed> $group Issue group.
	 * @return string
	 */
	private function get_export_howto( array $group ) {
		$key = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';
		$how = DUPCAP_Issue_Copy::group_howto( $key );

		if ( '' !== $how ) {
			return $how;
		}

		if ( ! empty( $group['action_label'] ) ) {
			return sprintf(
				/* translators: %s: action label */
				__( 'Use “%s” on the items below.', 'duplicate-content-addon-for-polylang' ),
				$group['action_label']
			);
		}

		return isset( $group['message'] ) ? (string) $group['message'] : '';
	}

	/**
	 * Plain-language how-to for an Elementor insight bucket.
	 *
	 * @param array<string, mixed> $bucket Insight bucket.
	 * @return string
	 */
	private function get_elementor_export_howto( array $bucket ) {
		$key = isset( $bucket['key'] ) ? (string) $bucket['key'] : '';
		$how = DUPCAP_Issue_Copy::elementor_howto( $key );

		return '' !== $how ? $how : ( isset( $bucket['message'] ) ? (string) $bucket['message'] : '' );
	}

	/**
	 * Decode HTML entities and collapse whitespace for Markdown body text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function md_plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Escape a value for a Markdown table cell.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function md_cell( $text ) {
		$text = $this->md_plain( $text );
		return str_replace( array( '|', "\n", "\r" ), array( '\\|', ' ', '' ), $text );
	}

		/**
		 * Everything the dashboard renders, as plain data.
		 *
		 * Grouped issues split into priority and remaining, passed checks,
		 * coverage, health score and pillars, counts, environment and Elementor
		 * insights. Reads the saved report; never starts a scan.
		 *
		 * @return array<string, mixed>
		 */
		public function get_report_context() {
			return $this->build_export_context( $this->get_scan_results() );
		}

		/**
		 * Build the downloadable report.
		 *
		 * @param string $format markdown|json. Anything else means markdown.
		 * @return array{content: string, filename: string, mime: string}
		 */
		public function export_report( $format ) {
			if ( ! in_array( $format, array( 'markdown', 'json' ), true ) ) {
				$format = 'markdown';
			}

			$context = $this->get_report_context();

			$site  = wp_parse_url( home_url() );
			$host  = isset( $site['host'] ) ? $site['host'] : sanitize_title( get_bloginfo( 'name' ) );
			$stamp = current_time( 'Y-m-d-His' );

			if ( 'json' === $format ) {
				$content  = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$filename = sprintf( 'translation-inspector-%s-%s.json', sanitize_file_name( $host ), $stamp );
				$mime     = 'application/json';
			} else {
				$content  = $this->build_markdown_report( $context );
				$filename = sprintf( 'translation-inspector-%s-%s.md', sanitize_file_name( $host ), $stamp );
				$mime     = 'text/markdown';
			}

			return array(
				'content'  => (string) $content,
				'filename' => $filename,
				'mime'     => $mime,
			);
		}

		private function build_export_context( array $results ) {
			$issue_groups   = $this->build_issue_groups( $results );
			$issue_groups   = $this->remove_front_page_from_content_groups( $issue_groups );
			$passed_checks  = $this->filter_results_by_severity( $results, 'pass' );
			$coverage       = $this->get_language_coverage( $results );
			$health_pillars = $this->get_health_pillars( $results, $coverage );
			$health_score   = $this->get_health_score( $health_pillars );
			$split          = $this->split_issue_groups_for_display( $issue_groups );
			$environment    = $this->get_environment_summary();
			$elementor      = $this->get_elementor_insights();
			$saved          = DUPCAP_Scanner::get_saved_report();

			if ( ! DUPCAP_Scanner::has_saved_scan() ) {
				$health_pillars = array(
					'coverage' => 0,
					'setup'    => 0,
					'builder'  => 0,
					'routing'  => 0,
				);
				$health_score = 0;
			}

			$counts           = $this->get_counts( $results, $issue_groups );
			$elementor_counts = $this->get_elementor_badge_counts();
			$counts['crit']  += $elementor_counts['crit'];
			$counts['warn']  += $elementor_counts['warn'];

			return array(
				'generated_at'    => DUPCAP_Scanner::format_scan_time( time() ),
				'site_url'        => home_url(),
				'site_name'       => get_bloginfo( 'name' ),
				'last_scanned'    => ! empty( $saved['time'] ) ? DUPCAP_Scanner::format_scan_time( $saved['time'] ) : __( 'Never scanned', 'duplicate-content-addon-for-polylang' ),
				'health_score'    => $health_score,
				'health_pillars'  => $health_pillars,
				'counts'          => $counts,
				'environment'     => $environment,
				'priority_issues' => $split['priority'],
				'grouped_issues'  => $split['remaining'],
				'passed_checks'   => $passed_checks,
				'coverage'        => $coverage,
				'elementor'       => $elementor,
			);
		}

}
