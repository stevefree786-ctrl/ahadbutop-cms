<?php
/**
 * The chat screen.
 *
 * @var array  $user
 * @var string $role
 * @var int    $rank
 * @var string $ceo_floor
 * @var bool   $can_use_ceo
 */

$canUse = (bool) ($can_use_ceo ?? false);
?>
<div class="panel notice" id="ceo-intro">
  <p>
    <strong>Ask for the outcome, not the command.</strong> The CEO breaks your
    request into steps and hands each one to the specialist that owns it —
    writing an article, restyling the site, creating a page, publishing a post.
    Every step is authorised against
    <strong>your own <?= e($role) ?> role</strong>, so it can do exactly what
    you could do by hand and nothing more. It cannot write SQL, and it cannot
    quietly raise its own permissions.
  </p>
</div>

<?php if (!$canUse): ?>
  <div class="panel notice warn">
    <p>
      The CEO can write, so it needs the <code><?= e($ceo_floor) ?></code> role.
      You are signed in as <code><?= e($role) ?></code>. Ask an admin to
      promote you, or use the individual agents you do have access to from the
      <a href="/admin/agents">Agents</a> screen.
    </p>
  </div>
<?php else: ?>

<div class="split">
  <div class="col-main">

    <div class="panel">
      <div class="panel-head">
        <h2>Conversation</h2>
        <div>
          <button type="button" class="btn btn-sm" id="chat-clear">Clear</button>
        </div>
      </div>

      <!--
        aria-live="polite" so a screen reader announces each new answer as it
        arrives rather than silently growing the log. role="log" gives assistive
        tech the turn structure.
      -->
      <div id="chat-log" class="chat-log" role="log" aria-live="polite"
           aria-label="Conversation"></div>

      <form id="chat-form" class="chat-form" autocomplete="off">
        <label class="sr-only" for="chat-input">What would you like done?</label>
        <textarea id="chat-input" name="task" rows="2" required
                  placeholder="e.g. Write an article about our pricing, then create a Pricing page and link it in the nav."
                  aria-describedby="chat-hint"></textarea>
        <div class="chat-actions">
          <button type="submit" class="btn btn-primary" id="chat-send">Send</button>
          <label class="checkbox" title="Show what the CEO will do before it does it">
            <input type="checkbox" id="chat-dry" checked> Preview first
          </label>
          <span id="chat-hint" class="muted chat-hint">
            Preview first is on — you'll get to read the plan before anything changes.
          </span>
        </div>
      </form>
    </div>

  </div>

  <div class="col-side">
    <div class="panel">
      <div class="panel-head"><h2>Try one of these</h2></div>
      <ul class="prompt-list">
        <?php
        $examples = [
            'Create a Pricing page and put it at the end of the nav',
            'Rename the About page to Company and keep the old link working',
            'Make the accent colour purple',
            'Write a post about how we work, and leave it as a draft',
            'Audit my posts for missing meta descriptions and fix them',
        ];
        foreach ($examples as $example): ?>
          <li>
            <button type="button" class="prompt-chip" data-prompt="<?= e($example) ?>">
              <?= e($example) ?>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>What it can do</h2></div>
      <p class="muted">
        Twenty-three validated actions across the fleet — creating and renaming
        pages, writing and publishing posts, restyling via design tokens,
        editing a theme's own templates, importing images, pulling in news.
      </p>
      <p class="muted">
        <a href="/admin/agents">See the full list →</a>
      </p>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Worth knowing</h2></div>
      <ul class="bullets muted">
        <li>
          Changes apply <strong>immediately</strong> — there is no staging
          environment. Preview first if you want to read a plan before it lands.
        </li>
        <li>
          This transcript lives in your browser tab only. Clearing it clears the
          history; the pages and posts it created are real and stay.
        </li>
        <li>
          Anything the AI does, you can undo by hand from the Posts and Agents
          screens.
        </li>
      </ul>
    </div>
  </div>
</div>

<?php endif; ?>