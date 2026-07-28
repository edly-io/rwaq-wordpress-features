<?php

/**
 * "Rwaq Quote" Gutenberg block.
 *
 * A dynamic block editors can drop into any post/page content: a styled pull
 * quote (light purple card, quotation glyph, justified text) with an author
 * attribution line. Because it renders through the_content, inside a blog post
 * it sits within .rwaq-bd__prose automatically; its own .rwaq-quote styles are
 * self-contained so it also looks right anywhere else.
 *
 * Kept intentionally to a single file with no build step: the block is
 * registered server-side (render_callback in PHP), and the small editor script
 * and the stylesheet are attached inline to registered handles rather than
 * shipped as separate .js / .css / block.json files.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Server-side render for the tutor-sso/quote block.
 *
 * @param array $attributes { quote: string(html), author: string(html) }.
 * @return string HTML, or '' when the block is empty.
 */
function quote_block_render($attributes)
{
	$quote  = isset($attributes['quote']) ? (string) $attributes['quote'] : '';
	$author = isset($attributes['author']) ? (string) $attributes['author'] : '';

	// Nothing meaningful entered — render nothing rather than an empty card.
	if ('' === trim(wp_strip_all_tags($quote)) && '' === trim(wp_strip_all_tags($author))) {
		return '';
	}

	ob_start();
?>
	<figure class="rwaq-quote" dir="rtl">
		<blockquote class="rwaq-quote__text"><?php echo wp_kses_post($quote); ?></blockquote>
		<?php if ('' !== trim(wp_strip_all_tags($author))) : ?>
			<figcaption class="rwaq-quote__author"><span class="rwaq-quote__author-name"><?php echo wp_kses_post($author); ?></span></figcaption>
		<?php endif; ?>
	</figure>
<?php
	return ob_get_clean();
}

/**
 * The block's front-end + editor stylesheet (inlined — see file header).
 *
 * @return string CSS.
 */
function quote_block_css()
{
	// Purple double-quote glyph, inlined as a data URI (only '#' needs encoding).
	$glyph = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23565199'%3E%3Cpath d='M9.5 5C6.5 6.4 5 9 5 12v6h6v-6H7.8c.1-1.6.9-2.9 2.7-3.7L9.5 5zm9 0C15.5 6.4 14 9 14 12v6h6v-6h-3.2c.1-1.6.9-2.9 2.7-3.7L18.5 5z'/%3E%3C/svg%3E";

	return "
	.rwaq-quote {
		position: relative;
		background: #F1F0F8;
		border-radius: 14px;
		padding: 30px 34px;
		margin: 0 0 24px;
		font-family: 'IBM Plex Sans Arabic', sans-serif;
		color: #424242;
	}
	.rwaq-quote::before {
		content: '';
		position: absolute;
		top: 24px;
		inset-inline-end: 28px;
		width: 40px;
		height: 40px;
		background: url(\"{$glyph}\") no-repeat center / contain;
		pointer-events: none;
	}
	/* Scoped (.rwaq-quote .rwaq-quote__text) to override generic prose/theme
	   blockquote styles (e.g. .rwaq-bd__prose blockquote) that would otherwise
	   paint a second background box inside the card. */
	.rwaq-quote .rwaq-quote__text {
		margin: 0;
		padding: 0;
		padding-inline-end: 56px;
		background: none;
		border: 0;
		border-radius: 0;
		text-align: justify;
		font-size: 15px;
		line-height: 2;
		color: #424242;
	}
	.rwaq-quote__author {
		display: flex;
		align-items: center;
		justify-content: flex-start;
		gap: 10px;
		margin-top: 16px;
		font-size: 13px;
		color: #616161;
	}
	.rwaq-quote__author::after {
		content: '';
		flex: none;
		width: 22px;
		height: 1px;
		background: #b9bec7;
	}
	.rwaq-quote__author-name {
		font-weight: 500;
	}
	";
}

/**
 * The no-build editor script (inlined — see file header). Uses the global wp.*
 * APIs via wp.element.createElement (no JSX to compile).
 *
 * @return string JavaScript.
 */
function quote_block_editor_js()
{
	return <<<'JS'
( function ( blocks, blockEditor, element, i18n ) {
	var el = element.createElement;
	var RichText = blockEditor.RichText;
	var useBlockProps = blockEditor.useBlockProps;
	var __ = i18n.__;

	blocks.registerBlockType( 'tutor-sso/quote', {
		apiVersion: 2,
		title: __( 'اقتباس رواق', 'tutor-sso' ),
		description: __( 'اقتباس منسّق مع اسم الكاتب.', 'tutor-sso' ),
		icon: 'format-quote',
		category: 'text',
		keywords: [ 'quote', 'اقتباس', 'rwaq' ],
		attributes: {
			quote: { type: 'string', default: '' },
			author: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps( { className: 'rwaq-quote', dir: 'rtl' } );

			return el(
				'figure',
				blockProps,
				el( RichText, {
					tagName: 'blockquote',
					className: 'rwaq-quote__text',
					value: attrs.quote,
					allowedFormats: [ 'core/bold', 'core/italic' ],
					onChange: function ( value ) { props.setAttributes( { quote: value } ); },
					placeholder: __( 'اكتب نص الاقتباس…', 'tutor-sso' )
				} ),
				el(
					'figcaption',
					{ className: 'rwaq-quote__author' },
					el( RichText, {
						tagName: 'span',
						className: 'rwaq-quote__author-name',
						value: attrs.author,
						allowedFormats: [],
						onChange: function ( value ) { props.setAttributes( { author: value } ); },
						placeholder: __( 'اسم الكاتب', 'tutor-sso' )
					} )
				)
			);
		},
		// Dynamic block: markup comes from the PHP render_callback.
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element, window.wp.i18n );
JS;
}

/**
 * Register the block plus its inline editor script and stylesheet.
 */
function quote_block_register()
{
	if (! function_exists('register_block_type')) {
		return;
	}

	// Editor script: an empty handle that carries the inline JS. Its wp-*
	// dependencies guarantee the editor APIs are loaded first.
	wp_register_script(
		'tutor-sso-quote-block',
		'',
		array('wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n'),
		TUTOR_SSO_VERSION,
		true
	);
	wp_add_inline_script('tutor-sso-quote-block', quote_block_editor_js());

	// Style handle (no external file) carrying the inline CSS. Used for both the
	// front end (enqueued only when the block is present) and the editor.
	wp_register_style('tutor-sso-quote-block', false, array(), TUTOR_SSO_VERSION);
	wp_add_inline_style('tutor-sso-quote-block', quote_block_css());

	register_block_type(
		'tutor-sso/quote',
		array(
			'api_version'     => 2,
			'editor_script'   => 'tutor-sso-quote-block',
			'style'           => 'tutor-sso-quote-block',
			'editor_style'    => 'tutor-sso-quote-block',
			'render_callback' => __NAMESPACE__ . '\\quote_block_render',
			'attributes'      => array(
				'quote'  => array('type' => 'string', 'default' => ''),
				'author' => array('type' => 'string', 'default' => ''),
			),
		)
	);
}
add_action('init', __NAMESPACE__ . '\\quote_block_register');
