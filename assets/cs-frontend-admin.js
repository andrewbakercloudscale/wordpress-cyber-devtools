/**
 * Frontend admin bar — Generate Featured Image button.
 * Injected on single post pages for manage_options users only.
 */
( function () {
    'use strict';

    var cfg     = window.csdtFrontAdmin || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce   = cfg.nonce   || '';

    /* ── Pill button + modal CSS ─────────────────────────────────────────── */
    var css = [
        /* pill */
        '.csdt-gen-bar{display:block!important;text-align:right!important;padding:0 0 20px!important;width:100%!important;box-sizing:border-box!important;}',
        '.csdt-gen-img-pill{display:inline-flex;align-items:center;gap:6px;background:#2271b1;color:#fff;border:none;border-radius:3px;padding:6px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;line-height:1.5;transition:background .15s;}',
        '.csdt-gen-img-pill:hover{background:#135e96;}',
        '.csdt-gen-img-pill:disabled{opacity:.55;cursor:not-allowed;}',
        /* overlay */
        '.csdt-gen-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;overflow-y:auto;padding:60px 20px;box-sizing:border-box;}',
        /* modal card — WP admin style */
        '.csdt-gen-modal{background:#fff;border-radius:4px;max-width:500px;margin:0 auto;box-shadow:0 4px 32px rgba(0,0,0,.35);font-size:13px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#3c434a;}',
        /* header */
        '.csdt-gen-modal-hdr{padding:14px 16px;border-bottom:1px solid #dcdcde;background:#f6f7f7;border-radius:4px 4px 0 0;display:flex;align-items:center;justify-content:space-between;}',
        '.csdt-gen-modal-hdr h3{margin:0;font-size:14px;font-weight:600;color:#1d2327;}',
        '.csdt-gen-modal-hdr button{background:none;border:none;cursor:pointer;padding:2px 6px;color:#787c82;font-size:22px;line-height:1;border-radius:3px;}',
        /* body */
        '.csdt-gen-modal-body{padding:20px 24px;}',
        /* grid rows: fixed label col + fluid control col */
        '.csdt-gen-row{display:grid;grid-template-columns:120px 1fr;align-items:center;gap:0 12px;margin-bottom:12px;}',
        '.csdt-gen-row label{font-size:13px;font-weight:600;color:#3c434a;text-align:right;}',
        '.csdt-gen-row select{width:100%;padding:5px 8px;font-size:13px;border:1px solid #8c8f94;border-radius:3px;background:#fff;color:#3c434a;line-height:1.8;}',
        '.csdt-gen-row .csdt-sel-pair{display:flex;gap:8px;align-items:center;}',
        '.csdt-gen-row .csdt-sel-pair select:first-child{flex:1;}',
        '.csdt-gen-row .csdt-sel-pair select:last-child{width:100px;}',
        /* status + images — indent to align with controls */
        '.csdt-gen-modal-msg{margin:4px 0 12px 132px;font-size:12px;color:#787c82;min-height:18px;}',
        '.csdt-gen-modal-imgs{margin-left:132px;display:flex;gap:10px;flex-wrap:wrap;margin-bottom:4px;}',
        '.csdt-gen-img-opt{flex:1 1 200px;border-radius:4px;overflow:hidden;cursor:pointer;border:3px solid transparent;transition:border-color .15s;}',
        '.csdt-gen-img-opt.selected{border-color:#2271b1;}',
        '.csdt-gen-img-opt img{width:100%;display:block;height:auto;}',
        /* prompt toggle */
        '.csdt-gen-prompt-row{margin:0 0 8px 132px;}',
        '.csdt-gen-prompt-toggle{font-size:11px;color:#787c82;background:none;border:none;cursor:pointer;padding:0;text-decoration:underline;}',
        '.csdt-gen-prompt-text{display:none;margin-top:6px;padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:11px;color:#3c434a;line-height:1.5;white-space:pre-wrap;word-break:break-word;}',
        /* footer */
        '.csdt-gen-modal-actions{padding:12px 16px;border-top:1px solid #dcdcde;background:#f6f7f7;border-radius:0 0 4px 4px;display:flex;align-items:center;gap:8px;}',
        '.csdt-gen-btn-regen{background:#2271b1;color:#fff;border:1px solid #135e96;border-radius:3px;padding:6px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}',
        '.csdt-gen-btn-regen:hover{background:#135e96;}',
        '.csdt-gen-btn-regen:disabled{opacity:.5;cursor:not-allowed;}',
        '.csdt-gen-btn-cancel{background:#fff;color:#3c434a;border:1px solid #c3c4c7;border-radius:3px;padding:6px 14px;font-size:13px;cursor:pointer;font-family:inherit;}',
        '.csdt-gen-btn-cancel:disabled{opacity:.5;cursor:not-allowed;}',
        '.csdt-gen-btn-spacer{flex:1;}',
        '.csdt-gen-btn-save{background:#00a32a;color:#fff;border:1px solid #00a32a;border-radius:3px;padding:6px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}',
        '.csdt-gen-btn-save:hover{background:#007017;}',
        '.csdt-gen-btn-save:disabled{opacity:.35;cursor:not-allowed;}',
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
        '  <div class="csdt-gen-modal-hdr">',
        '    <h3>🎨 Generate Featured Image</h3>',
        '    <button type="button" id="csdt-gen-close" title="Close">&times;</button>',
        '  </div>',
        '  <div class="csdt-gen-modal-body">',
        '    <div class="csdt-gen-row">',
        '      <label for="csdt-gen-article-style">Article style</label>',
        '      <select id="csdt-gen-article-style">' + articleStyleOptHtml + '</select>',
        '    </div>',
        '    <div class="csdt-gen-row">',
        '      <label for="csdt-gen-bg-color">Background</label>',
        '      <select id="csdt-gen-bg-color">',
        '        <option value="auto">Auto (match style)</option>',
        '        <option value="light_grey">⬜ Light grey / off-white</option>',
        '        <option value="warm_cream">🟡 Warm cream / golden</option>',
        '        <option value="white">◻ Clean white</option>',
        '        <option value="sky_blue">🔵 Sky blue / outdoor</option>',
        '        <option value="gradient">🌅 Soft gradient</option>',
        '        <option value="dark">⬛ Dark / dramatic</option>',
        '      </select>',
        '    </div>',
        '    <div class="csdt-gen-row">',
        '      <label for="csdt-gen-style">Image style</label>',
        '      <select id="csdt-gen-style">' + styleOptHtml + '</select>',
        '    </div>',
        '    <div class="csdt-gen-row">',
        '      <label for="csdt-gen-quality">Quality</label>',
        '      <select id="csdt-gen-quality">',
        '        <option value="standard" selected>Standard</option>',
        '        <option value="hd">HD</option>',
        '      </select>',
        '    </div>',
        '    <div class="csdt-gen-row">',
        '      <label>Post title</label>',
        '      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#3c434a;font-weight:400;">',
        '        <input type="checkbox" id="csdt-gen-overlay" style="width:16px;height:16px;cursor:pointer">',
        '        Include Post Title in image',
        '      </label>',
        '    </div>',
        '    <div class="csdt-gen-prompt-row" id="csdt-gen-prompt-row" style="display:none;">',
        '      <button type="button" class="csdt-gen-prompt-toggle" id="csdt-gen-prompt-toggle">▶ View prompt</button>',
        '      <div class="csdt-gen-prompt-text" id="csdt-gen-prompt-text"></div>',
        '    </div>',
        '    <div class="csdt-gen-modal-msg" id="csdt-gen-msg">Click Generate to create an image for this post.</div>',
        '    <div class="csdt-gen-modal-imgs" id="csdt-gen-imgs"></div>',
        '  </div>',
        '  <div class="csdt-gen-modal-actions">',
        '    <button type="button" class="csdt-gen-btn-save" id="csdt-gen-save" disabled>✔ Save as Featured Image</button>',
        '    <span class="csdt-gen-btn-spacer"></span>',
        '    <button type="button" class="csdt-gen-btn-regen" id="csdt-gen-regen">⚙ Generate</button>',
        '    <button type="button" class="csdt-gen-btn-cancel" id="csdt-gen-cancel">Cancel</button>',
        '  </div>',
        '</div>',
    ].join( '' );
    document.body.appendChild( bg );

    var msgEl         = document.getElementById( 'csdt-gen-msg' );
    var imgsEl        = document.getElementById( 'csdt-gen-imgs' );
    var saveBtn       = document.getElementById( 'csdt-gen-save' );
    var regenBtn      = document.getElementById( 'csdt-gen-regen' );
    var cancelBtn     = document.getElementById( 'csdt-gen-cancel' );
    var closeBtn      = document.getElementById( 'csdt-gen-close' );
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

    var articleStyleEl = document.getElementById( 'csdt-gen-article-style' );
    var bgColorEl      = document.getElementById( 'csdt-gen-bg-color' );
    var overlayEl      = document.getElementById( 'csdt-gen-overlay' );

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

        var style        = styleEl        ? styleEl.value        : ( cfg.imgStyle || 'auto' );
        var quality      = qualEl         ? qualEl.value         : 'standard';
        var articleStyle = articleStyleEl ? articleStyleEl.value : 'general';
        var bgColor      = bgColorEl      ? bgColorEl.value      : 'auto';

        post( 'csdt_devtools_ai_image_generate', {
            post_id:         currentPostId,
            quality:         quality,
            prompt_vendor:   cfg.promptVendor || 'openai',
            prompt_model:    cfg.promptModel  || 'gpt-4o-mini',
            prompt_style:    style,
            article_style:   articleStyle,
            bg_color:        bgColor,
            include_overlay: overlayEl && overlayEl.checked ? '1' : '0',
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
    if ( closeBtn  ) { closeBtn.addEventListener(  'click', discard ); }

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
