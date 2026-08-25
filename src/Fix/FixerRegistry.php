<?php
/**
 * The set of available fixers.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

use SEOAgent\Fix\Fixers\AnchorTextFixer;
use SEOAgent\Fix\Fixers\BrokenLinkFixer;
use SEOAgent\Fix\Fixers\CanonicalFixer;
use SEOAgent\Fix\Fixers\FaqSchemaFixer;
use SEOAgent\Fix\Fixers\HeadingLevelFixer;
use SEOAgent\Fix\Fixers\ImageAltFixer;
use SEOAgent\Fix\Fixers\InlineImageAltFixer;
use SEOAgent\Fix\Fixers\InternalLinksFixer;
use SEOAgent\Fix\Fixers\LlmsTxtFixer;
use SEOAgent\Fix\Fixers\MetaDescriptionFixer;
use SEOAgent\Fix\Fixers\MetaTitleFixer;
use SEOAgent\Fix\Fixers\PostSlugFixer;
use SEOAgent\Fix\Fixers\ProductShortDescriptionFixer;
use SEOAgent\Fix\Fixers\SiteVisibilityFixer;
use SEOAgent\Fix\Fixers\TermCopyFixer;
use SEOAgent\Fix\Fixers\TermMetaFixer;
use SEOAgent\Fix\Fixers\TermRobotsFixer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixers addressable by slug.
 */
final class FixerRegistry {

	/** @var array<string,FixerInterface> */
	private $fixers = array();

	/**
	 * Registry with every bundled fixer.
	 */
	public static function withDefaults(): FixerRegistry {
		$registry = new self();

		$defaults = array(
			new MetaTitleFixer(),
			new MetaDescriptionFixer(),
			new ImageAltFixer(),
			new InlineImageAltFixer(),
			new HeadingLevelFixer(),
			new PostSlugFixer(),
			new CanonicalFixer(),
			new TermMetaFixer(),
			new TermRobotsFixer(),
			new TermCopyFixer(),
			new ProductShortDescriptionFixer(),
			new FaqSchemaFixer(),
			new SiteVisibilityFixer(),
			new LlmsTxtFixer(),
			new BrokenLinkFixer(),
			new AnchorTextFixer(),
			new InternalLinksFixer(),
		);

		foreach ( $defaults as $fixer ) {
			$registry->add( $fixer );
		}

		/**
		 * Filter the fixer registry.
		 *
		 * @param FixerRegistry $registry Registry with the bundled fixers.
		 */
		return apply_filters( 'seo_agent_fixers', $registry );
	}

	/**
	 * Register a fixer.
	 *
	 * @param FixerInterface $fixer Fixer instance.
	 */
	public function add( FixerInterface $fixer ): FixerRegistry {
		$this->fixers[ $fixer->slug() ] = $fixer;

		return $this;
	}

	/**
	 * One fixer.
	 *
	 * @param string $slug Fixer slug.
	 */
	public function get( string $slug ): ?FixerInterface {
		return $this->fixers[ $slug ] ?? null;
	}

	/**
	 * Every fixer.
	 *
	 * @return array<string,FixerInterface>
	 */
	public function all(): array {
		return $this->fixers;
	}

	/**
	 * Describe the registry for the REST catalogue.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function describe(): array {
		$out = array();

		foreach ( $this->fixers as $slug => $fixer ) {
			$out[] = array(
				'slug'           => $slug,
				'label'          => $fixer->label(),
				'deterministic'  => $fixer->is_deterministic(),
				'required_input' => $fixer->required_input(),
			);
		}

		return $out;
	}
}
