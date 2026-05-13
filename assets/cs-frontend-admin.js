/**
 * Frontend admin bar — Generate Featured Image button.
 * Injected on single post pages for manage_options users only.
 */
( function () {
    'use strict';

    var cfg     = window.csdtFrontAdmin || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce   = cfg.nonce   || '';

    /* ── Pill button CSS ─────────────────────────────────────────────────── */
    var css = [
        '.csdt-gen-bar{display:block!important;text-align:right!important;padding:0 0 20px!important;width:100%!important;box-sizing:border-box!important;}',
        '.csdt-gen-img-pill{',
        '  display:inline-flex;align-items:center;gap:6px;',
        '  background:#0057ff;color:#fff;border:none;border-radius:20px;',
        '  padding:5px 15px;font-size:12px;font-weight:600;cursor:pointer;',
        '  font-family:inherit;line-height:1.5;transition:opacity .15s;',
        '}',
        '.csdt-gen-img-pill:hover{opacity:.85;}',
        '.csdt-gen-img-pill:disabled{opacity:.55;cursor:not-allowed;}',
        /* modal */
        '.csdt-gen-modal-bg{',
        '  display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);',
        '  z-index:999999;overflow-y:auto;padding:40px 16px;box-sizing:border-box;',
        '}',
        '.csdt-gen-modal{',
        '  background:#fff;border-radius:12px;max-width:640px;margin:0 auto;',
        '  padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.4);',
        '}',
        '.csdt-gen-modal h3{margin:0 0 16px;font-size:16px;color:#111827;}',
        '.csdt-gen-modal-imgs{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}',
        '.csdt-gen-img-opt{',
        '  flex:1 1 240px;border-radius:8px;overflow:hidden;cursor:pointer;',
        '  border:3px solid transparent;transition:border-color .15s;',
        '}',
        '.csdt-gen-img-opt.selected{border-color:#2563eb;}',
        '.csdt-gen-img-opt img{width:100%;display:block;height:auto;}',
        '.csdt-gen-modal-actions{display:flex;gap:8px;flex-wrap:wrap;}',
        '.csdt-gen-btn-save{',
        '  background:#16a34a;color:#fff;border:none;border-radius:20px;',
        '  padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;',
        '}',
        '.csdt-gen-btn-save:disabled{opacity:.5;cursor:not-allowed;}',
        '.csdt-gen-btn-regen,.csdt-gen-btn-cancel{',
        '  background:#111827;color:#fff;border:none;border-radius:20px;',
        '  padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;',
        '}',
        '.csdt-gen-btn-regen:disabled,.csdt-gen-btn-cancel:disabled{opacity:.5;cursor:not-allowed;}',
        '.csdt-gen-modal-msg{font-size:12px;color:#64748b;margin-bottom:8px;min-height:18px;}',
        '.csdt-gen-style-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;}',
        '.csdt-gen-style-row label{font-size:12px;font-weight:600;color:#374151;}',
        '.csdt-gen-style-row select{font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:3px 8px;background:#fff;}',
        '.csdt-gen-prompt-row{margin-top:10px;}',
        '.csdt-gen-prompt-toggle{font-size:11px;color:#6b7280;background:none;border:none;cursor:pointer;padding:0;text-decoration:underline;}',
        '.csdt-gen-prompt-text{display:none;margin-top:6px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;color:#374151;line-height:1.5;white-space:pre-wrap;word-break:break-word;}',
        '.csdt-gen-mode-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;}',
        '.csdt-gen-mode-row label{font-size:12px;font-weight:600;color:#374151;}',
        '.csdt-gen-mode-toggle{display:inline-flex;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;}',
        '.csdt-gen-mode-btn{padding:4px 12px;font-size:12px;font-weight:400;cursor:pointer;border:none;background:#f9fafb;color:#374151;font-family:inherit;}',
        '.csdt-gen-mode-btn.active{background:#1565c0;color:#fff;font-weight:600;}',
        '.csdt-gen-article-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;}',
        '.csdt-gen-article-row label{font-size:12px;font-weight:600;color:#374151;}',
        '.csdt-gen-article-row select{font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:3px 8px;background:#fff;}',
    ].join( '' );

    var style = document.createElement( 'style' );
    style.textContent = css;
    document.head.appendChild( style );

    /* ── Modal DOM ───────────────────────────────────────────────────────── */
    var styleOptHtml = ( csdtFrontAdmin.styleOptions || [] ).map( function( s ) {
        return '<option value="' + s.val + '">' + s.label + '</option>';
    } ).join( '' );

    var articleStyleOptHtml = ( csdtFrontAdmin.articleStyleOptions || [] ).map( function( s ) {
        return '<option value="' + s.val + '">' + s.label + '</option>';
    } ).join( '' );

    var bg = document.createElement( 'div' );
    bg.className = 'csdt-gen-modal-bg';
    bg.innerHTML = [
        '<div class="csdt-gen-modal">',
        '  <h3>🎨 Generate Featured Image</h3>',
        '  <div class="csdt-gen-mode-row">',
        '    <label>Mode:</label>',
        '    <div class="csdt-gen-mode-toggle">',
        '      <button type="button" class="csdt-gen-mode-btn active" id="csdt-gen-mode-classic" data-mode="classic">📸 Classic</button>',
        '      <button type="button" class="csdt-gen-mode-btn" id="csdt-gen-mode-visual" data-mode="visual_summary">🎯 Visual Summary</button>',
        '    </div>',
        '  </div>',
        '  <div class="csdt-gen-article-row" id="csdt-gen-article-row" style="display:none">',
        '    <label for="csdt-gen-article-style">Article style:</label>',
        '    <select id="csdt-gen-article-style">' + articleStyleOptHtml + '</select>',
        '  </div>',
        '  <div class="csdt-gen-style-row">',
        '    <label for="csdt-gen-style">Style:</label>',
        '    <select id="csdt-gen-style">' + styleOptHtml + '</select>',
        '    <label for="csdt-gen-quality">Quality:</label>',
        '    <select id="csdt-gen-quality">',
        '      <option value="standard" selected>Standard</option>',
        '      <option value="hd">HD</option>',
        '    </select>',
        '  </div>',
        '  <div class="csdt-gen-prompt-row" id="csdt-gen-prompt-row" style="display:none;">',
        '    <button type="button" class="csdt-gen-prompt-toggle" id="csdt-gen-prompt-toggle">▶ View prompt</button>',
        '    <div class="csdt-gen-prompt-text" id="csdt-gen-prompt-text"></div>',
        '  </div>',
        '  <div class="csdt-gen-modal-msg" id="csdt-gen-msg">Click Generate to create an image for this post.</div>',
        '  <div class="csdt-gen-modal-imgs" id="csdt-gen-imgs"></div>',
        '  <div class="csdt-gen-modal-actions">',
        '    <button type="button" class="csdt-gen-btn-save" id="csdt-gen-save" disabled>✔ Save as Featured Image</button>',
        '    <button type="button" class="csdt-gen-btn-regen" id="csdt-gen-regen">⚙ Generate</button>',
        '    <button type="button" class="csdt-gen-btn-cancel" id="csdt-gen-cancel">✕ Cancel</button>',
        '  </div>',
        '</div>',
    ].join( '' );
    document.body.appendChild( bg );

    var msgEl         = document.getElementById( 'csdt-gen-msg' );
    var imgsEl        = document.getElementById( 'csdt-gen-imgs' );
    var saveBtn       = document.getElementById( 'csdt-gen-save' );
    var regenBtn      = document.getElementById( 'csdt-gen-regen' );
    var cancelBtn     = document.getElementById( 'csdt-gen-cancel' );
    var styleEl       = document.getElementById( 'csdt-gen-style' );
    var qualEl        = document.getElementById( 'csdt-gen-quality' );
    var promptRow     = document.getElementById( 'csdt-gen-prompt-row' );
    var promptToggle  = document.getElementById( 'csdt-gen-prompt-toggle' );
    var promptText    = document.getElementById( 'csdt-gen-prompt-text' );

    if ( promptToggle ) {
        promptToggle.addEventListener( 'click', function () {
            var open = promptText.style.display !== 'none';
            promptText.style.display = open ? 'none' : 'block';
            promptToggle.textContent = ( open ? '▶' : '▼' ) + ' View prompt';
        } );
    }

    // ── Mode toggle ───────────────────────────────────────────────────────
    var modeClassicBtn  = document.getElementById( 'csdt-gen-mode-classic' );
    var modeVisualBtn   = document.getElementById( 'csdt-gen-mode-visual' );
    var articleRow      = document.getElementById( 'csdt-gen-article-row' );
    var articleStyleEl  = document.getElementById( 'csdt-gen-article-style' );

    function applyGenMode( mode ) {
        var isVS = ( mode === 'visual_summary' );
        if ( modeClassicBtn ) { modeClassicBtn.classList.toggle( 'active', ! isVS ); }
        if ( modeVisualBtn  ) { modeVisualBtn.classList.toggle( 'active',   isVS ); }
        if ( articleRow     ) { articleRow.style.display = isVS ? '' : 'none'; }
        localStorage.setItem( 'csdt_img_gen_mode', mode );
    }

    var initMode = localStorage.getItem( 'csdt_img_gen_mode' ) || 'visual_summary';
    applyGenMode( initMode );

    if ( modeClassicBtn ) { modeClassicBtn.addEventListener( 'click', function () { applyGenMode( 'classic' ); } ); }
    if ( modeVisualBtn  ) { modeVisualBtn.addEventListener(  'click', function () { applyGenMode( 'visual_summary' ); } ); }

    // Pre-fill style from saved settings; quality always defaults to standard
    if ( cfg.imgStyle && styleEl ) { styleEl.value = cfg.imgStyle; }

    var currentPostId = 0;
    var pendingIds    = [];
    var selectedId    = 0;

    /* ── Helpers ─────────────────────────────────────────────────────────── */
    function setMsg( txt ) { if ( msgEl ) msgEl.textContent = txt; }

    function setBusy( busy ) {
        if ( regenBtn  ) regenBtn.disabled  = busy;
        if ( cancelBtn ) cancelBtn.disabled = busy;
        if ( saveBtn   ) saveBtn.disabled   = busy || ! selectedId;
    }

    function post( action, extra, cb ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce',  nonce );
        Object.keys( extra ).forEach( function ( k ) { fd.append( k, extra[ k ] ); } );
        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( cb )
            .catch( function () { cb( { success: false, data: { message: 'Network error.' } } ); } );
    }

    /* ── Generate ────────────────────────────────────────────────────────── */
    function generate() {
        if ( ! currentPostId ) return;
        imgsEl.innerHTML = '';
        selectedId = 0;
        saveBtn.disabled = true;
        setBusy( true );
        setMsg( '⏳ Writing prompt…' );

        var style        = styleEl       ? styleEl.value       : ( cfg.imgStyle || 'auto' );
        var quality      = qualEl        ? qualEl.value        : 'standard';
        var genMode      = localStorage.getItem( 'csdt_img_gen_mode' ) || 'classic';
        var articleStyle = articleStyleEl ? articleStyleEl.value : 'general';

        post( 'csdt_devtools_ai_image_generate', {
            post_id:       currentPostId,
            quality:       quality,
            prompt_vendor: cfg.promptVendor || 'openai',
            prompt_model:  cfg.promptModel  || 'gpt-4o-mini',
            prompt_style:  style,
            mode:          genMode,
            article_style: articleStyle,
        }, function ( startResp ) {
            if ( ! startResp.success || ! startResp.data || ! startResp.data.job_id ) {
                setBusy( false );
                setMsg( '✕ ' + ( ( startResp.data && startResp.data.message ) || 'Failed to start generation.' ) );
                return;
            }
            var jobId      = startResp.data.job_id;
            var elapsed    = 0;
            var MAX_POLL_S = 300;
            setMsg( '⏳ Generating… (0s)' );
            var pollTimer = setInterval( function () {
                elapsed += 4;
                if ( elapsed > MAX_POLL_S ) {
                    clearInterval( pollTimer );
                    setBusy( false );
                    setMsg( '✕ Timed out after 5 min — check server error logs' );
                    return;
                }
                var m = Math.floor( elapsed / 60 ), s = elapsed % 60;
                setMsg( '⏳ Generating… (' + ( m > 0 ? m + 'm ' : '' ) + s + 's)' );
                post( 'csdt_devtools_ai_image_poll', { job_id: jobId }, function ( pollResp ) {
                    var st = pollResp.data && pollResp.data.status;
                    if ( st === 'pending' || st === 'processing' ) return;
                    clearInterval( pollTimer );
                    setBusy( false );
                    if ( ! pollResp.success ) {
                        var expMsg = st === 'expired' ? 'Job expired — server may have timed out' : ( ( pollResp.data && ( pollResp.data.error || pollResp.data.message ) ) || 'Request failed' );
                        setMsg( '✕ ' + expMsg );
                        return;
                    }
                    if ( st === 'error' ) {
                        setMsg( '✕ ' + ( ( pollResp.data && pollResp.data.error ) || 'Generation failed (no error detail — check PHP error log)' ) );
                        return;
                    }
                    var result  = pollResp.data.result || {};
                    var options = result.options || [];
                    if ( ! options.length ) {
                        setMsg( '✕ Generation failed — no images returned' );
                        return;
                    }
                    if ( result.prompt && promptRow && promptText ) {
                        promptText.textContent = result.prompt;
                        promptRow.style.display = 'block';
                        promptText.style.display = 'none';
                        if ( promptToggle ) { promptToggle.textContent = '▶ View prompt'; }
                    }
                    pendingIds = options.map( function ( o ) { return o.attach_id; } );
                    setMsg( options.length > 1 ? 'Click an image to select it, then save.' : 'Review the image and save or regenerate.' );
                    imgsEl.innerHTML = '';
                    options.forEach( function ( opt ) {
                        var wrap = document.createElement( 'div' );
                        wrap.className = 'csdt-gen-img-opt' + ( options.length === 1 ? ' selected' : '' );
                        wrap.dataset.attachId = opt.attach_id;
                        var img = document.createElement( 'img' );
                        img.src = opt.thumb_url || opt.full_url;
                        img.alt = 'Generated option';
                        wrap.appendChild( img );
                        wrap.addEventListener( 'click', function () {
                            imgsEl.querySelectorAll( '.csdt-gen-img-opt' ).forEach( function ( el ) { el.classList.remove( 'selected' ); } );
                            wrap.classList.add( 'selected' );
                            selectedId = opt.attach_id;
                            saveBtn.disabled = false;
                        } );
                        imgsEl.appendChild( wrap );
                    } );
                    if ( options.length === 1 ) {
                        selectedId = options[0].attach_id;
                        saveBtn.disabled = false;
                    }
                } );
            }, 4000 );
        } );
    }

    /* ── Save ────────────────────────────────────────────────────────────── */
    function save() {
        if ( ! selectedId || ! currentPostId ) return;
        setBusy( true );
        setMsg( '⏳ Saving as featured image…' );
        var discardIds = pendingIds.filter( function ( id ) { return id !== selectedId; } );
        post( 'csdt_devtools_ai_image_pick', {
            post_id:   currentPostId,
            attach_id: selectedId,
            discard:   discardIds.join( ',' ),
        }, function ( resp ) {
            setBusy( false );
            if ( resp.success ) {
                setMsg( '✔ Saved! Refreshing page…' );
                // Update the featured image on the page without full reload
                var newUrl = resp.data && resp.data.thumb_url ? resp.data.thumb_url : '';
                if ( newUrl ) {
                    var heroImg = document.querySelector( '.wp-post-image' );
                    if ( heroImg ) { heroImg.src = newUrl; }
                }
                pendingIds = [];
                selectedId = 0;
                setTimeout( function () { window.location.reload(); }, 800 );
            } else {
                setMsg( '✕ ' + ( ( resp.data && resp.data.message ) || 'Save failed.' ) );
            }
        } );
    }

    /* ── Discard (cancel) ────────────────────────────────────────────────── */
    function discard() {
        if ( pendingIds.length ) {
            post( 'csdt_devtools_ai_image_discard', { attach_ids: pendingIds.join( ',' ) }, function () {} );
            pendingIds = [];
        }
        selectedId = 0;
        bg.style.display = 'none';
        imgsEl.innerHTML = '';
        setMsg( 'Click Generate to create an image for this post.' );
        setBusy( false );
        saveBtn.disabled = true;
        if ( promptRow )  { promptRow.style.display = 'none'; }
        if ( promptText ) { promptText.style.display = 'none'; promptText.textContent = ''; }
    }

    /* ── Wire up buttons ─────────────────────────────────────────────────── */
    if ( regenBtn  ) { regenBtn.addEventListener(  'click', generate ); }
    if ( saveBtn   ) { saveBtn.addEventListener(   'click', save ); }
    if ( cancelBtn ) { cancelBtn.addEventListener( 'click', discard ); }

    bg.addEventListener( 'click', function ( e ) {
        if ( e.target === bg ) discard();
    } );
    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key === 'Escape' && bg.style.display !== 'none' ) discard();
    } );

    /* ── Position bar above category/tags section ───────────────────────── */
    function positionBar( bar ) {
        // Prefer entry footer (cats/tags), then footer meta, then end of content
        var anchor = document.querySelector( '.entry-footer' )
                  || document.querySelector( '.cat-links' )
                  || document.querySelector( '.tags-links' )
                  || document.querySelector( '.post-categories' )
                  || document.querySelector( '.entry-meta' )
                  || document.querySelector( '.entry-content' )
                  || document.querySelector( 'article' );
        if ( anchor ) {
            anchor.parentNode.insertBefore( bar, anchor );
        } else {
            document.body.insertBefore( bar, document.body.firstChild );
        }
        bar.style.display = 'block';
    }

    /* ── Wire up pill button ─────────────────────────────────────────────── */
    function bindBar() {
        var bar = document.getElementById( 'csdt-gen-bar' );
        if ( ! bar || bar._csdtBound ) return;
        bar._csdtBound = true;
        positionBar( bar );
        var pid = parseInt( bar.dataset.postId, 10 );
        bar.querySelectorAll( '.csdt-gen-img-pill' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                currentPostId = pid;
                selectedId    = 0;
                pendingIds    = [];
                imgsEl.innerHTML = '';
                saveBtn.disabled = true;
                setMsg( 'Click Generate to create an image for this post.' );
                bg.style.display = 'block';
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bindBar );
    } else {
        bindBar();
    }

} )();
