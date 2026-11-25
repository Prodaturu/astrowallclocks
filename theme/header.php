<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
$source = file_get_contents(get_theme_file_path('design/index.html'));
if (preg_match('~(<div class="announcement">.*?</div>\s*<header class="site-header">.*?</header>)~s', $source, $match)) {
    echo astro_design_html($match[1], 'header'); // Trusted design source.
}
?>
<main class="inner-main"><section class="page-heading"><div class="container"><p class="eyebrow">EKSHAN STORE</p><h1><?php echo esc_html(get_the_title(get_queried_object_id())); ?></h1></div></section>
<section class="cart-section section-pad"><div class="container">
