<?php
/**
 * Visibility in AI answer engines.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\SiteChecker;
use SEOAgent\Support\Html;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the things that determine whether an answer engine can find, trust
 * and cite this site.
 *
 * Two of these are business decisions rather than defects — whether to let AI
 * crawlers in at all, and whether to publish an llms.txt — so they are reported
 * as information with both sides stated, not as problems to fix. Reporting a
 * deliberate choice as an error is how audit tools lose credibility.
 */
final class AiVisibilityChecker extends SiteChecker {

	/**
	 * Crawlers used to build and ground answer engines.
	 *
	 * @var array<string,string>
	 */
	private const AI_CRAWLERS = array(
		'gptbot'          => 'OpenAI (training)',
		'oai-searchbot'   => 'OpenAI (ChatGPT search)',
		'chatgpt-user'    => 'ChatGPT (user-initiated fetch)',
		'claudebot'       => 'Anthropic (training)',
		'claude-user'     => 'Claude (user-initiated fetch)',
		'claude-searchbot' => 'Claude (search)',
		'perplexitybot'   => 'Perplexity',
		'google-extended' => 'Google Gemini grounding',
		'applebot-extended' => 'Apple Intelligence',
		'bingbot'         => 'Bing / Copilot',
	);

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'ai_visibility';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'AI search visibility', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'discovery';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Checks whether answer engines can crawl the site, and whether it gives them the entity and authorship signals they need to cite it.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_site( AuditContext $context ): array {
		$issues = array();

		$robots = $context->fetcher->get( RobotsChecker::url(), HOUR_IN_SECONDS );

		if ( '' === $robots['error'] && $robots['status'] < 400 ) {
			$issues = array_merge( $issues, $this->check_crawler_access( $robots['body'] ) );
		}

		$issues = array_merge( $issues, $this->check_llms_txt( $context ) );

		return array_merge( $issues, $this->check_entity_signals( $context ) );
	}

	/**
	 * Which answer engines are blocked in robots.txt.
	 *
	 * @param string $body robots.txt contents.
	 *
	 * @return Issue[]
	 */
	private function check_crawler_access( string $body ): array {
		$rules   = RobotsChecker::parse( $body );
		$blocked = array();

		foreach ( self::AI_CRAWLERS as $agent => $label ) {
			$agent_rules = $rules[ $agent ] ?? null;

			if ( null === $agent_rules ) {
				continue;
			}

			if ( in_array( '/', $agent_rules['disallow'], true ) ) {
				$blocked[ $agent ] = $label;
			}
		}

		if ( empty( $blocked ) ) {
			return array();
		}

		return array(
			$this->issue(
				array(
					'code'        => 'ai.crawler_blocked',
					'severity'    => Issue::SEVERITY_INFO,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Answer-engine crawlers are blocked in robots.txt', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: %s: comma-separated crawler names. */
						__( 'robots.txt blocks %s. Reported for confirmation rather than as a fault: blocking training crawlers is a legitimate choice, but blocking the search and user-fetch agents also removes the site from the answers those products give.', 'seo-automation' ),
						implode( ', ', $blocked )
					),
					'url'         => RobotsChecker::url(),
					'evidence'    => array(
						'blocked'        => $blocked,
						'affected_count' => count( $blocked ),
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			),
		);
	}

	/**
	 * Is there an llms.txt at the root?
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function check_llms_txt( AuditContext $context ): array {
		$url      = home_url( '/llms.txt' );
		$response = $context->fetcher->check( $url, HOUR_IN_SECONDS );

		if ( $response['status'] > 0 && $response['status'] < 400 ) {
			return array();
		}

		return array(
			$this->issue(
				array(
					'code'        => 'ai.llms_txt.missing',
					'severity'    => Issue::SEVERITY_INFO,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'No llms.txt', 'seo-automation' ),
					'detail'      => __( 'llms.txt is an emerging convention for handing answer engines a curated map of your most useful pages. Adoption is not universal and no engine currently requires it, so treat this as an opportunity rather than a defect.', 'seo-automation' ),
					'url'         => $url,
					'evidence'    => array( 'status' => $response['status'] ),
					'fixer'       => 'llms_txt',
					'fix_mode'    => Issue::MODE_ASSISTED,
					'fix_payload' => array(),
				)
			),
		);
	}

	/**
	 * Does the home page identify the organisation behind the site?
	 *
	 * An answer engine will only attribute a claim to a source it can name.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function check_entity_signals( AuditContext $context ): array {
		$response = $context->fetcher->get( home_url( '/' ), HOUR_IN_SECONDS );

		if ( '' !== $response['error'] || $response['status'] >= 400 ) {
			return array();
		}

		$nodes = array();
		foreach ( Html::json_ld( $response['body'] ) as $graph ) {
			foreach ( Html::flatten_json_ld( $graph ) as $node ) {
				$nodes[] = $node;
			}
		}

		$organisation = null;
		foreach ( $nodes as $node ) {
			$types = array_map( 'strtolower', Html::node_types( $node ) );

			if ( array_intersect( $types, array( 'organization', 'localbusiness', 'person', 'store', 'corporation' ) ) ) {
				$organisation = $node;
				break;
			}
		}

		$issues = array();

		if ( null === $organisation ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'ai.entity.undefined',
					'severity'    => Issue::SEVERITY_MEDIUM,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'The site does not identify who publishes it', 'seo-automation' ),
					'detail'      => __( 'The home page emits no Organization, LocalBusiness or Person node. Answer engines cite sources they can name and connect to a known entity; without one the site is quotable but not attributable.', 'seo-automation' ),
					'url'         => home_url( '/' ),
					'evidence'    => array( 'types_found' => array_slice( array_map( static fn( $node ) => Html::node_types( $node ), $nodes ), 0, 10 ) ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);

			return $issues;
		}

		if ( empty( $organisation['sameAs'] ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'ai.entity.no_sameas',
					'severity'    => Issue::SEVERITY_LOW,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Publisher entity has no sameAs links', 'seo-automation' ),
					'detail'      => __( 'The organisation node names the publisher but does not link it to profiles elsewhere. sameAs is what lets an engine reconcile this site with the same entity in its knowledge graph.', 'seo-automation' ),
					'url'         => home_url( '/' ),
					'evidence'    => array( 'organisation' => array_slice( (array) $organisation, 0, 12 ) ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		if ( empty( $organisation['logo'] ) && empty( $organisation['image'] ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'ai.entity.no_logo',
					'severity'    => Issue::SEVERITY_LOW,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Publisher entity has no logo', 'seo-automation' ),
					'detail'      => __( 'A logo on the organisation node is what surfaces beside a citation in answer results and knowledge panels.', 'seo-automation' ),
					'url'         => home_url( '/' ),
					'evidence'    => array( 'organisation' => array_slice( (array) $organisation, 0, 12 ) ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		return $issues;
	}
}
