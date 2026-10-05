<?php
/** Fallback view when a theme has no template for this content type. */
?>
<div class="page-intro">
  <h1><?= $this->e($post['title'] ?? 'Untitled') ?></h1>
</div>
<div class="post-body">
  <?= $post['body_html'] ?? '' ?>
</div>
