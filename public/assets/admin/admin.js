/* Admin UI behaviour.
 *
 * Every mutation goes through the same /api/v1 endpoints the REST API exposes,
 * so there is one set of role checks and one CSRF story rather than a second,
 * admin-only path. Nothing here trusts its own DOM: a button's data-attribute
 * is a hint, and the server validates everything again.
 */
(function () {
  'use strict';

  var TOKEN_KEY = 'cms.token';

  // ---- token -------------------------------------------------------------

  function token() {
    try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; }
  }
  function setToken(t) {
    try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch (e) {}
  }

  /**
   * The CSRF token to send with a write.
   *
   * window.CSRF is embedded server-side per render. The cookie is the
   * fallback for a page rendered without one — and the two must agree,
   * because CsrfMiddleware only compares the header against the signed
   * token's subject, so a stale cookie is worse than none.
   */
  function csrf() {
    if (window.CSRF) { return window.CSRF; }
    var m = document.cookie.match(/(?:^|;\s*)cms_csrf=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  /**
   * Call the API.
   *
   * The CSRF token is sent alongside the bearer token: the JWT proves who the
   * caller is, the CSRF header proves the request came from a page this app
   * rendered (a cross-origin form post would carry the cookie but not a token
   * it cannot read).
   */
  function api(method, path, body) {
    var headers = {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf()
    };
    var t = token();
    if (t) { headers.Authorization = 'Bearer ' + t; }

    return fetch(path, {
      method: method,
      headers: headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body)
    }).then(function (res) {
      return res.text().then(function (raw) {
        var data = {};
        try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = { error: raw }; }
        if (!res.ok) {
          var err = new Error(data.error || ('HTTP ' + res.status));
          err.status = res.status;
          err.body = data;
          throw err;
        }
        return data;
      });
    });
  }

  // ---- flash -------------------------------------------------------------

  var flashTimer = null;
  function flash(message, kind) {
    var host = document.getElementById('flash');
    if (!host) { return; }
    host.textContent = message;
    host.className = 'flash flash-' + (kind || 'ok');
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () {
      host.textContent = '';
      host.className = '';
    }, 4000);
  }

  /** Turn an API failure into a message a person can act on. */
  function reportError(err) {
    var msg = err && err.message ? err.message : 'Something went wrong';
    if (err && err.status === 401) {
      setToken('');
      msg = 'Your session expired — sign in again.';
    }
    flash(msg, 'bad');
  }

  // ---- drawer ------------------------------------------------------------

  function openDrawer(id) {
    var d = document.getElementById(id);
    if (!d) { return; }
    d.hidden = false;
    var focusable = d.querySelector('input, select, textarea, button');
    if (focusable) { focusable.focus(); }
  }
  function closeDrawer(d) {
    if (typeof d === 'string') { d = document.getElementById(d); }
    if (d) { d.hidden = true; }
  }

  document.addEventListener('click', function (ev) {
    var closer = ev.target.closest('[data-close]');
    if (closer) { closeDrawer(closer.getAttribute('data-close')); }
    if (ev.target.id === 'drawer-close') { closeDrawer('drawer'); }
    // Clicking the backdrop (but not the panel) closes the drawer.
    if (ev.target.classList && ev.target.classList.contains('drawer')) {
      closeDrawer(ev.target);
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      Array.prototype.forEach.call(document.querySelectorAll('.drawer'), function (d) {
        if (!d.hidden) { d.hidden = true; }
      });
    }
  });

  // ---- logout -----------------------------------------------------------

  var logout = document.getElementById('logout');
  if (logout) {
    logout.addEventListener('click', function () {
      var refresh = null;
      try { refresh = localStorage.getItem('cms.refresh'); } catch (e) {}
      var done = function () {
        setToken('');
        try { localStorage.removeItem('cms.refresh'); } catch (e) {}
        window.location.href = '/admin/login';
      };
      if (refresh) {
        api('POST', '/api/v1/auth/logout', { refresh_token: refresh })
          .then(done, done);
      } else {
        done();
      }
    });
  }

  // ---- login ------------------------------------------------------------

  var loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var email = loginForm.elements.email.value.trim();
      var password = loginForm.elements.password.value;
      var err = document.getElementById('login-error');
      err.textContent = '';
      err.hidden = true;

      api('POST', '/api/v1/auth/login', { email: email, password: password })
        .then(function (data) {
          setToken(data.access_token);
          try { if (data.refresh_token) { localStorage.setItem('cms.refresh', data.refresh_token); } } catch (e) {}

          // Where the visitor was actually trying to go. The server already
          // validated this value before rendering the hidden field; the
          // re-check here is belt-and-braces, because assigning it to
          // location.href is what turns a bad value into an open redirect.
          var next = document.getElementById('next-target');
          var dest = next && next.value;
          if (!dest || dest.charAt(0) !== '/' || dest.slice(0, 2) === '//' || dest.slice(0, 2) === '/\\') {
            dest = '/admin';
          }
          window.location.href = dest;
        })
        .catch(function (e) {
          // Deliberately vague: telling an attacker whether the address exists
          // turns the login form into a user-enumeration oracle.
          err.textContent = 'Those credentials did not work.';
          err.hidden = false;
        });
    });
  }

  // ---- generic delegated actions ----------------------------------------

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-action]');
    if (!btn || btn.tagName !== 'BUTTON') { return; }

    var action = btn.getAttribute('data-action');
    var id = btn.getAttribute('data-id');
    var busy = false;

    if (action === 'toggle-publish') {
      var status = btn.getAttribute('data-status');
      var publish = status !== 'published';
      busy = true;
      btn.disabled = true;
      api(publish ? 'POST' : 'PUT', '/api/v1/posts/' + id + (publish ? '/publish' : '/unpublish'), {})
        .then(function () {
          flash(publish ? 'Published.' : 'Moved back to draft.');
          setTimeout(function () { window.location.reload(); }, 500);
        })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'cancel-job') {
      busy = true; btn.disabled = true;
      api('POST', '/api/v1/jobs/' + id + '/cancel', {})
        .then(function () { flash('Job cancelled.'); setTimeout(function () { location.reload(); }, 500); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'retry-job') {
      busy = true; btn.disabled = true;
      api('POST', '/api/v1/jobs/' + id + '/retry', {})
        .then(function () { flash('Requeued.'); setTimeout(function () { location.reload(); }, 500); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'job-logs') {
      api('GET', '/api/v1/jobs/' + id)
        .then(function (data) {
          var body = document.getElementById('drawer-body');
          var pre = document.createElement('pre');
          pre.className = 'agent-output';
          pre.textContent = JSON.stringify(data, null, 2);
          body.innerHTML = '';
          body.appendChild(pre);
          document.getElementById('drawer-title').textContent = 'Job #' + id;
          openDrawer('drawer');
        })
        .catch(reportError);

    } else if (action === 'delete-media') {
      if (!window.confirm('Delete this file permanently? Posts referencing it will render without it.')) { return; }
      busy = true; btn.disabled = true;
      api('DELETE', '/api/v1/media/' + id)
        .then(function () { location.reload(); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'delete-user') {
      if (!window.confirm('Remove this user? Their sessions end immediately.')) { return; }
      busy = true; btn.disabled = true;
      api('DELETE', '/api/v1/users/' + id)
        .then(function () { location.reload(); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'delete-tag' || action === 'delete-category') {
      var kind = action === 'delete-tag' ? 'tag' : 'category';
      if (!window.confirm('Delete this ' + kind + '? Posts keep their content; only the link is removed.')) { return; }
      busy = true; btn.disabled = true;
      api('DELETE', '/api/v1/taxonomy/' + kind + '/' + id)
        .then(function () { location.reload(); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'ask-agent') {
      var select = document.getElementById('agent-select');
      if (select) { select.value = btn.getAttribute('data-agent'); }
      openDrawer('agent-drawer');

    } else if (action === 'run-skill') {
      var skillSelect = document.getElementById('skill-select');
      if (skillSelect) {
        skillSelect.value = btn.getAttribute('data-skill');
        // Rebuild the parameter fields for the newly-selected skill —
        // otherwise the form shows the previous skill's inputs and posts
        // parameter names the new one does not have.
        renderSkillParams(skillSelect.value);
      }
      openDrawer('skill-drawer');

    } else if (action === 'save-key') {
      var provider = btn.getAttribute('data-provider');
      var field = document.getElementById('byok-' + provider);
      var key = field ? field.value.trim() : '';
      if (!key) { flash('Paste a key first.', 'bad'); return; }

      var byokOut = document.getElementById('byok-output');
      if (byokOut) { byokOut.textContent = 'Encrypting and saving…'; }

      busy = true; btn.disabled = true;
      // A dedicated endpoint, NOT /agents/run with the key inside a task
      // string. An agent run puts the prompt through the model provider, so
      // routing a live credential that way would ship it to Kilo. The role
      // check here is the same hasRole() every other admin route uses, and
      // the endpoint never accepts an intent name from the caller.
      api('POST', '/api/v1/ai/providers', { provider: provider, api_key: key })
        .then(function () {
          if (byokOut) { byokOut.textContent = ''; }
          location.reload();
        })
        .catch(function (e) {
          if (byokOut) { byokOut.textContent = ''; }
          btn.disabled = false;
          reportError(e);
        });

    } else if (action === 'clear-key') {
      var prov = btn.getAttribute('data-provider');
      if (!window.confirm('Remove the saved key for ' + prov + '? The CMS falls back to .env for that provider.')) { return; }
      busy = true; btn.disabled = true;
      api('DELETE', '/api/v1/ai/providers/' + encodeURIComponent(prov))
        .then(function () { location.reload(); })
        .catch(function (e) { btn.disabled = false; reportError(e); });

    } else if (action === 'seo-fix') {
      busy = true; btn.disabled = true;
      api('POST', '/api/v1/jobs', { type: 'seo.audit', payload: { post_id: Number(id) } })
        .then(function () { flash('Queued an SEO audit for that post.'); })
        .catch(function (e) { reportError(e); btn.disabled = false; });

    } else if (action === 'enqueue') {
      var type = window.prompt('Job type:\n' + (window.JOB_TYPES || '').join('\n'));
      if (!type) { return; }
      api('POST', '/api/v1/jobs', { type: type, payload: {} })
        .then(function () { flash('Queued ' + type + '.'); setTimeout(function () { location.reload(); }, 500); })
        .catch(reportError);
    }

    if (busy) { ev.preventDefault(); }
  });

  // ---- inline selects (role change) -------------------------------------

  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-action="set-role"]');
    if (!el) { return; }
    var id = el.getAttribute('data-id');
    api('PATCH', '/api/v1/users/' + id, { role: el.value })
      .then(function () { flash('Role updated.'); })
      .catch(function (e) { reportError(e); location.reload(); });
  });

  // ---- copy path --------------------------------------------------------

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) { return; }
    var text = btn.getAttribute('data-copy');
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () { flash('Copied ' + text); }, function () { flash('Copy failed', 'bad'); });
    } else {
      flash('Path: ' + text);
    }
  });

  // ---- taxonomy + settings forms ---------------------------------------

  document.addEventListener('submit', function (ev) {
    var form = ev.target;

    if (form.classList.contains('inline-add')) {
      ev.preventDefault();
      var kind = form.getAttribute('data-action') === 'create-tag' ? 'tags' : 'categories';
      var name = form.elements.name.value.trim();
      if (!name) { return; }
      api('POST', '/api/v1/taxonomy/' + kind, { name: name })
        .then(function () { location.reload(); })
        .catch(reportError);

    } else if (form.id === 'settings-form') {
      ev.preventDefault();
      var payload = {};
      Array.prototype.forEach.call(form.querySelectorAll('[name^="settings["]'), function (input) {
        if (!input.disabled) { payload[input.name] = input.value; }
      });
      api('PUT', '/api/v1/settings', payload)
        .then(function () { flash('Settings saved.'); })
        .catch(reportError);

    } else if (form.id === 'agent-form') {
      ev.preventDefault();
      var out = document.getElementById('agent-output');
      out.textContent = 'Working…';
      api('POST', '/api/v1/agents/run', {
        agent: form.elements.agent.value,
        task: form.elements.task.value
      })
        .then(function (data) {
          out.textContent = JSON.stringify(data, null, 2);
          flash('Agent finished.');
        })
        .catch(function (e) { out.textContent = ''; reportError(e); });

    } else if (form.id === 'skill-form') {
      ev.preventDefault();
      var skillOut = document.getElementById('skill-output');
      skillOut.textContent = 'Running…';

      // Collect only the fields this skill actually declares. Sending the
      // previous skill's inputs is harmless (the server ignores unknown
      // params) but sending a stale post_id would silently act on the wrong
      // post, so the form is rebuilt on selection instead.
      var skillParams = {};
      var inputs = form.querySelectorAll('#skill-params [name]');
      for (var i = 0; i < inputs.length; i++) {
        var v = inputs[i].value.trim();
        if (v !== '') { skillParams[inputs[i].getAttribute('name')] = v; }
      }

      api('POST', '/api/v1/skills/run', { skill: form.elements.skill.value, params: skillParams })
        .then(function (data) {
          skillOut.textContent = describeSkillRun(data);
          // A partial run is not a success. Reloading only when every step
          // ran keeps a half-applied skill visible instead of implying it
          // never happened — the output says exactly what stopped.
          if (data.status === 'ok') {
            flash(data.done + ' step(s) done.');
            setTimeout(function () { location.reload(); }, 1200);
          } else {
            flash(data.summary || 'That skill did not complete.', 'bad');
          }
        })
        .catch(function (e) { skillOut.textContent = ''; reportError(e); });
    }
  });

  // ---- skills ------------------------------------------------------------

  var SKILL_PARAMS = (window.SKILLS || []);

  /**
   * Render a text input per declared parameter.
   *
   * A skill's params are names only, not types — publish_page wants a post_id
   * and seo_optimise_post wants a keyword, and both are strings on the wire.
   * The server casts and validates, so the field stays a plain text box rather
   * than guessing "is this numeric?" and being wrong about an id that has not
   * been chosen yet.
   */
  function renderSkillParams(name) {
    var host = document.getElementById('skill-params');
    if (!host) { return; }

    var match = null;
    for (var i = 0; i < SKILL_PARAMS.length; i++) {
      if (SKILL_PARAMS[i].name === name) { match = SKILL_PARAMS[i]; break; }
    }

    host.textContent = '';
    if (!match || !match.params || match.params.length === 0) {
      var none = document.createElement('p');
      none.className = 'hint';
      none.textContent = 'This skill takes no parameters.';
      host.appendChild(none);
      return;
    }

    match.params.forEach(function (param) {
      var label = document.createElement('label');
      label.className = 'field';

      var span = document.createElement('span');
      span.className = 'label';
      span.textContent = param;

      var input = document.createElement('input');
      input.type = 'text';
      input.name = param;
      input.placeholder = param === 'post_id' ? 'e.g. 12' : 'e.g. ' + param;

      label.appendChild(span);
      label.appendChild(input);
      host.appendChild(label);
    });
  }

  /**
   * A run's per-step result as prose, because JSON is not a status report.
   *
   * The distinction the text has to preserve: 'denied' means the step was
   * REFUSED and nothing was written, while 'rejected' means the parameters
   * were invalid. Collapsing both into "error" would tell an operator to fix
   * their role when the real problem was a mistyped id.
   */
  function describeSkillRun(data) {
    var lines = [];
    var steps = data.steps || [];

    steps.forEach(function (s, i) {
      var label = (i + 1) + '. ' + (s.intent || '?') + ' — ' + (s.status || '?');
      if (s.error) { label += ': ' + s.error; }
      lines.push(label);
    });

    if (!lines.length) { return JSON.stringify(data, null, 2); }
    lines.push('');
    lines.push(data.summary || '');
    return lines.join('\n');
  }

  // Selecting a different skill rebuilds its parameter fields.
  document.addEventListener('change', function (ev) {
    if (ev.target.id === 'skill-select') { renderSkillParams(ev.target.value); }
  });

  // ---- post editor ------------------------------------------------------

  var postForm = document.getElementById('post-form');
  if (postForm) {
    var body = document.getElementById('body');
    var counter = document.getElementById('wordcount');

    var updateCount = function () {
      var text = body.value.replace(/```[\s\S]*?```/g, ' ');
      var words = text.trim() ? text.trim().split(/\s+/).length : 0;
      var mins = Math.max(1, Math.ceil(words / 220));
      counter.textContent = words + ' words · ' + mins + ' min read';
    };
    body.addEventListener('input', updateCount);
    updateCount();

    var meta = postForm.querySelector('[name="meta_description"]');
    var metaLen = document.getElementById('meta-len');
    if (meta && metaLen) {
      meta.addEventListener('input', function () { metaLen.textContent = meta.value.length + '/160'; });
    }

    postForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var submit = postForm.querySelector('button[type=submit]');
      var isNew = postForm.getAttribute('data-action') === 'create';
      var id = postForm.getAttribute('data-id');

      var payload = {
        title: postForm.elements.title.value,
        slug: postForm.elements.slug.value,
        excerpt: postForm.elements.excerpt.value,
        body_md: postForm.elements.body.value,
        status: postForm.elements.status.value,
        type: postForm.elements.type.value,
        focus_keyword: postForm.elements.focus_keyword.value,
        meta_description: postForm.elements.meta_description.value,
        tag_ids: Array.prototype.slice.call(postForm.querySelectorAll('[name="tag_ids[]"]:checked')).map(function (i) { return Number(i.value); }),
        category_ids: Array.prototype.slice.call(postForm.querySelectorAll('[name="category_ids[]"]:checked')).map(function (i) { return Number(i.value); })
      };

      submit.disabled = true;
      submit.textContent = 'Saving…';

      var request = isNew
        ? api('POST', '/api/v1/posts', payload)
        : api('PUT', '/api/v1/posts/' + id, payload);

      request.then(function (data) {
        // SEO fields live in a polymorphic table keyed by entity, so they are a
        // separate call — the post write does not own them.
        if (payload.focus_keyword || payload.meta_description) {
          return api('POST', '/api/v1/seo/' + (data.id || id), {
            focus_keyword: payload.focus_keyword,
            meta_description: payload.meta_description
          }).then(function () { return data; });
        }
        return data;
      }).then(function (data) {
        flash('Saved.');
        // A newly created post needs an id before it can be edited again.
        window.location.href = (isNew ? '/admin/editor/' : '/admin/editor/') + (data.id || id);
      }).catch(function (e) {
        reportError(e);
        submit.disabled = false;
        submit.textContent = isNew ? 'Create' : 'Save changes';
      });
    });

    // Preview: render the Markdown client-side into the drawer. It is a
    // preview of what you typed, never a fetch, so it cannot 500 on a bad post.
    var previewBtn = document.getElementById('preview');
    if (previewBtn) {
      previewBtn.addEventListener('click', function () {
        document.getElementById('preview-body').innerHTML = renderMarkdown(body.value);
        openDrawer('preview-drawer');
      });
    }

    var seoBtn = document.getElementById('seo-suggest');
    if (seoBtn) {
      seoBtn.addEventListener('click', function () {
        seoBtn.disabled = true;
        seoBtn.textContent = 'Thinking…';
        api('POST', '/api/v1/agents/run', {
          agent: 'SeoAgent',
          task: 'Suggest a meta description and focus keyword for: ' + postForm.elements.title.value
        }).then(function (data) {
          var result = data.result || data;
          if (result.meta_description) { meta.value = result.meta_description; }
          if (result.focus_keyword) { postForm.elements.focus_keyword.value = result.focus_keyword; }
          flash('Suggestions applied.');
        }).catch(reportError).then(function () {
          seoBtn.disabled = false;
          seoBtn.textContent = 'Suggest with SEO agent';
        });
      });
    }
  }

  // ---- misc buttons ----------------------------------------------------

  var recompute = document.getElementById('recompute-tags');
  if (recompute) {
    recompute.addEventListener('click', function () {
      recompute.disabled = true;
      api('POST', '/api/v1/taxonomy/tags/recompute', {})
        .then(function (d) { flash('Recounted ' + (d.count || 0) + ' tags.'); })
        .catch(reportError)
        .then(function () { recompute.disabled = false; });
    });
  }

  var crawl = document.getElementById('crawl-seo');
  if (crawl) {
    crawl.addEventListener('click', function () {
      crawl.disabled = true;
      api('POST', '/api/v1/jobs', { type: 'seo.audit', payload: {} })
        .then(function () { flash('Queued SEO audits.'); })
        .catch(reportError)
        .then(function () { crawl.disabled = false; });
    });
  }

  // ---- minimal markdown -------------------------------------------------

  /**
   * A deliberately small Markdown subset for the preview pane.
   *
   * Input is escaped FIRST, so no raw HTML in the post body can survive into
   * the preview. Everything after that point inserts markup we generated.
   */
  function renderMarkdown(src) {
    var blocks = String(src || '').replace(/\r\n/g, '\n').split(/\n{2,}/);
    var out = [];

    blocks.forEach(function (block) {
      var text = escapeHtml(block);

      if (/^```/.test(text)) {
        out.push('<pre><code>' + text.replace(/^```\w*\n?/, '').replace(/```$/, '') + '</code></pre>');
        return;
      }
      if (/^#{1,6}\s/.test(text)) {
        var m = text.match(/^(#{1,6})\s+(.*)$/);
        var lvl = Math.min(6, m[1].length);
        out.push('<h' + lvl + '>' + inline(m[2]) + '</h' + lvl + '>');
        return;
      }
      if (/^>\s?/.test(text)) {
        out.push('<blockquote>' + inline(text.replace(/^>\s?/gm, '')) + '</blockquote>');
        return;
      }
      if (/^([-*]|\d+\.)\s/.test(text)) {
        var ordered = /^\d+\.\s/.test(text);
        var items = text.split('\n').filter(Boolean).map(function (line) {
          return '<li>' + inline(line.replace(/^([-*]|\d+\.)\s+/, '')) + '</li>';
        }).join('');
        out.push((ordered ? '<ol>' : '<ul>') + items + (ordered ? '</ol>' : '</ul>'));
        return;
      }
      out.push('<p>' + inline(text).replace(/\n/g, '<br>') + '</p>');
    });

    return out.join('');
  }

  function inline(s) {
    return s
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2" rel="noopener">$1</a>');
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ---- the chat screen ----------------------------------------------------
  //
  // Session-scoped, not persisted. A transcript of prompts is not content:
  // storing one would need retention and deletion rules nobody asked for.
  // The cost is stated plainly in the UI — clearing the tab clears the
  // history, though the pages and posts it created are permanent regardless.

  var CHAT_KEY = 'cms.chat';
  // Enough to keep the thread coherent without letting an old exchange
  // silently steer a new request. The model gets the last 12 turns.
  var CHAT_TURNS = 12;

  var log = document.getElementById('chat-log');
  var form = document.getElementById('chat-form');

  function loadTurns() {
    try {
      var raw = sessionStorage.getItem(CHAT_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function saveTurns(turns) {
    try {
      sessionStorage.setItem(CHAT_KEY, JSON.stringify(turns.slice(-CHAT_TURNS)));
    } catch (e) {}
  }

  function bubble(role, html) {
    var el = document.createElement('div');
    el.className = 'chat-msg chat-' + role;
    // The HTML here is built by this file from escaped values only. Every
    // interpolated piece goes through escapeHtml() at the point of
    // concatenation — nothing model-supplied reaches innerHTML raw.
    el.innerHTML = html;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  /** Render one step row from a plan or a result. */
  function stepRow(step, executed) {
    var status = step.status || 'planned';
    var tone = status === 'ok' ? 'good' : (status === 'planned' ? '' : 'bad');
    var label = { ok: 'done', denied: 'not permitted', rejected: 'refused',
                  failed: 'failed', error: 'error', planned: 'planned' }[status] || status;

    var out = '<div class="chat-step ' + (tone ? 'tone-' + tone : '') + '">'
      + '<code>' + escapeHtml(step.intent || '?') + '</code>'
      + '<span class="muted"> via ' + escapeHtml(step.specialist || 'AdminAgent') + '</span>'
      + '<span class="pill">' + escapeHtml(label) + '</span>';

    if (step.why) { out += '<p class="muted chat-why">' + escapeHtml(step.why) + '</p>'; }
    if (step.error) { out += '<p class="chat-error">' + escapeHtml(step.error) + '</p>'; }

    // On a real run, surface the URL a created page can be visited at. That is
    // the single most useful thing to learn from a write, and it is otherwise
    // buried in the JSON.
    if (executed && step.result && step.result.url) {
      out += '<p><a href="' + escapeHtml(step.result.url) + '" target="_blank" rel="noopener">'
           + escapeHtml(step.result.url) + ' →</a></p>';
    }
    return out + '</div>';
  }

  function renderPlan(turns, data, executed) {
    var plan = data.plan || data.steps || [];
    var html = '';

    if (data.summary) { html += '<p>' + escapeHtml(data.summary) + '</p>'; }

    if (plan.length) {
      html += '<div class="chat-steps">';
      for (var i = 0; i < plan.length; i++) { html += stepRow(plan[i], executed); }
      html += '</div>';
    }

    if (!executed && plan.length) {
      html += '<div class="chat-plan-actions">'
            + '<button type="button" class="btn btn-primary btn-sm" data-run-plan>Run this plan</button>'
            + '<button type="button" class="btn btn-sm" data-cancel-plan>Never mind</button>'
            + '</div>';
    }

    turns.push({ role: 'assistant', html: html, plan: plan });
    saveTurns(turns);
    bubble('assistant', html);
  }

  function drawTurns() {
    log.innerHTML = '';
    var turns = loadTurns();
    for (var i = 0; i < turns.length; i++) {
      bubble(turns[i].role, turns[i].html);
    }
    if (turns.length === 0) {
      bubble('assistant', '<p class="muted">Describe what you want done. '
        + 'I\'ll show you the plan first.</p>');
    }
    return turns;
  }

  if (form) {
    var input = document.getElementById('chat-input');
    var dryBox = document.getElementById('chat-dry');
    var sendBtn = document.getElementById('chat-send');
    var turns = drawTurns();

    // Example prompts in the sidebar fill the box but do not send — the
    // point is to let someone edit the wording before committing to it.
    document.addEventListener('click', function (ev) {
      var chip = ev.target.closest('.prompt-chip');
      if (chip) {
        input.value = chip.getAttribute('data-prompt');
        input.focus();
      }

      var run = ev.target.closest('[data-run-plan]');
      if (run) {
        var pending = null;
        for (var i = turns.length - 1; i >= 0; i--) {
          if (turns[i].plan && turns[i].plan.length) { pending = turns[i]; break; }
        }
        if (!pending) { return; }
        send(pending.plan);
      }

      if (ev.target.closest('[data-cancel-plan]')) { drawTurns(); turns = loadTurns(); }
    });

    function send(plan) {
      var task = plan ? null : input.value.trim();
      if (!plan && !task) { return; }

      if (!plan) {
        turns.push({ role: 'user', html: '<p>' + escapeHtml(task) + '</p>' });
        bubble('user', '<p>' + escapeHtml(task) + '</p>');
        saveTurns(turns);
        input.value = '';
      }

      var pending = bubble('assistant', '<p class="muted chat-thinking">Working…</p>');
      sendBtn.disabled = true;

      var payload = plan ? { plan: plan } : { task: task, dry_run: !!dryBox.checked };

      api('POST', '/api/v1/agents/ceo', payload)
        .then(function (data) {
          pending.remove();
          renderPlan(turns, data, !plan);
          turns = loadTurns();
          if (data.dry_run && data.plan && data.plan.length) {
            flash('Plan ready — review it, then run it.');
          }
        })
        .catch(function (e) {
          pending.remove();
          var msg = '<p class="chat-error">' + escapeHtml(e.message || 'Something went wrong') + '</p>';
          turns.push({ role: 'assistant', html: msg, plan: null });
          saveTurns(turns);
          bubble('assistant', msg);
        })
        .then(function () { sendBtn.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      send(null);
    });

    // Enter sends; Shift+Enter is a newline. Without this a multi-sentence
    // prompt needs the mouse.
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        send(null);
      }
    });

    var clearBtn = document.getElementById('chat-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        try { sessionStorage.removeItem(CHAT_KEY); } catch (e) {}
        turns = drawTurns();
      });
    }
  }
})();