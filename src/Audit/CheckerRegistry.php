<?php
/**
 * The set of checkers an audit can run.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

use SEOAgent\Audit\Checkers\AiVisibilityChecker;
use SEOAgent\Audit\Checkers\BrokenLinkChecker;
use SEOAgent\Audit\Checkers\CanonicalChecker;
use SEOAgent\Audit\Checkers\CategorySeoChecker;
use SEOAgent\Audit\Checkers\ContentQualityChecker;
use SEOAgent\Audit\Checkers\CoreWebVitalsChecker;
use SEOAgent\Audit\Checkers\DuplicateContentChecker;
use SEOAgent\Audit\Checkers\DuplicateMetaChecker;
use SEOAgent\Audit\Checkers\FaqChecker;
use SEOAgent\Audit\Checkers\HeadingChecker;
use SEOAgent\Audit\Checkers\ImageAltChecker;
use SEOAgent\Audit\Checkers\InternalLinkChecker;
use SEOAgent\Audit\Checkers\MetaDescriptionChecker;
use SEOAgent\Audit\Checkers\MetaTitleChecker;
use SEOAgent\Audit\Checkers\ProductSeoChecker;
use SEOAgent\Audit\Checkers\RobotsChecker;
use SEOAgent\Audit\Checkers\SchemaChecker;
use SEOAgent\Audit\Checkers\SitemapChecker;
use SEOAgent\Audit\Checkers\UrlStructureChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordered collection of checkers, addressable by slug.
 */
final class CheckerRegistry {

	/** @var array<string,CheckerInterface> */
	private $checkers = array();

	/**
	 * Registry with every bundled checker, in run order.
	 *
	 * Cheap metadata checks run first so a partial audit still produces the
	 * most actionable findings; network-bound checks run last.
	 */
	public static function withDefaults(): CheckerRegistry {
		$registry = new self();

		$defaults = array(
			new MetaTitleChecker(),
			new MetaDescriptionChecker(),
			new DuplicateMetaChecker(),
			new HeadingChecker(),
			new ImageAltChecker(),
			new UrlStructureChecker(),
			new ContentQualityChecker(),
			new InternalLinkChecker(),
			new DuplicateContentChecker(),
			new CategorySeoChecker(),
			new ProductSeoChecker(),
			new FaqChecker(),
			new RobotsChecker(),
			new SitemapChecker(),
			new CanonicalChecker(),
			new SchemaChecker(),
			new AiVisibilityChecker(),
			new BrokenLinkChecker(),
			new CoreWebVitalsChecker(),
		);

		foreach ( $defaults as $checker ) {
			$registry->add( $checker );
		}

		/**
		 * Filter the checker registry, to add or remove checks.
		 *
		 * @param CheckerRegistry $registry Registry with the bundled checkers.
		 */
		return apply_filters( 'seo_agent_checkers', $registry );
	}

	/**
	 * Register a checker, replacing any with the same slug.
	 *
	 * @param CheckerInterface $checker Checker instance.
	 */
	public function add( CheckerInterface $checker ): CheckerRegistry {
		$this->checkers[ $checker->slug() ] = $checker;

		return $this;
	}

	/**
	 * Remove a checker by slug.
	 *
	 * @param string $slug Checker slug.
	 */
	public function remove( string $slug ): CheckerRegistry {
		unset( $this->checkers[ $slug ] );

		return $this;
	}

	/**
	 * One checker.
	 *
	 * @param string $slug Checker slug.
	 */
	public function get( string $slug ): ?CheckerInterface {
		return $this->checkers[ $slug ] ?? null;
	}

	/**
	 * Every registered checker, in order.
	 *
	 * @return array<string,CheckerInterface>
	 */
	public function all(): array {
		return $this->checkers;
	}

	/**
	 * Checkers applicable to this site, optionally narrowed to a scope list.
	 *
	 * @param string[] $scopes Checker slugs or group names; empty means all.
	 *
	 * @return array<string,CheckerInterface>
	 */
	public function resolve( array $scopes = array() ): array {
		$resolved = array();

		foreach ( $this->checkers as $slug => $checker ) {
			if ( ! $checker->is_applicable() ) {
				continue;
			}

			if ( ! empty( $scopes )
				&& ! in_array( $slug, $scopes, true )
				&& ! in_array( $checker->group(), $scopes, true ) ) {
				continue;
			}

			$resolved[ $slug ] = $checker;
		}

		return $resolved;
	}

	/**
	 * Describe the registry for the REST catalogue.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function describe(): array {
		$out = array();

		foreach ( $this->checkers as $slug => $checker ) {
			$out[] = array(
				'slug'        => $slug,
				'label'       => $checker->label(),
				'group'       => $checker->group(),
				'description' => $checker->description(),
				'applicable'  => $checker->is_applicable(),
			);
		}

		return $out;
	}
}
