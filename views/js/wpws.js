jQuery(document).ready(function($) {
  $('#wpws-sandbox').tabs(); 
  $('#load_default_args').click(function() { 
    $( '#args' ).val( $(this).attr('data-args') );
  });
  
  // Quick Sandbox examples: prefill URL, Query, and Other Arguments
  $(document).on('click', '.wpws-sandbox-example', function(e) {
    e.preventDefault();
    var $link = $(this);
    var url = $link.data('url') || '';
    var query = $link.data('query') || '';
    var args = $link.data('args') || '';
    $('#url').val(url);
    $('#query').val(query);
    $('#args').val(args);
  });
  
  // Tooltip for complex options
  $('.wpws-tooltip').hover(function() {
    var tooltip = $(this).data('tooltip');
    if (tooltip) {
      $(this).append('<div class="wpws-tooltip-text" style="position:absolute;background:#333;color:#fff;padding:5px 10px;border-radius:3px;font-size:11px;z-index:9999;white-space:nowrap;">' + tooltip + '</div>');
    }
  }, function() {
    $('.wpws-tooltip-text').remove();
  });

  // Open WPWS guide links in a single named tab/window
  $(document).on('click', '.wpws-guide-link', function(e) {
    var href = $(this).attr('href');
    if (!href) {
      return;
    }
    e.preventDefault();
    window.open(href, 'wpwsGuide');
  });

  // Sandbox: open Source URL in new tab
  $('#wpws-open-url-btn').on('click', function() {
    var url = $('#url').val();
    if (typeof url === 'string') {
      url = url.trim();
    }
    if (!url) {
      return;
    }
    if (url.indexOf('http://') !== 0 && url.indexOf('https://') !== 0) {
      url = 'https://' + url;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
  });

  // Sandbox: Clear shortcode field
  $('#wpws-shortcode-clear').on('click', function() {
    $('#shortcode_test').val('');
  });

  // Sandbox: load a ready-made shortcode example into the textarea
  $(document).on('click', '.wpws-shortcode-example', function(e) {
    e.preventDefault();
    var sc = $(this).attr('data-shortcode');
    if (sc) {
      $('#shortcode_test').val(sc).focus();
    }
  });

  // Sandbox: Fill Source URL, Query, Other Arguments from shortcode text
  $('#wpws-shortcode-fill-form').on('click', function() {
    var raw = $('#shortcode_test').val();
    if (typeof raw !== 'string' || !raw.trim()) {
      return;
    }
    raw = raw.trim();
    // Match shortcode: [wpws ...] or [wpws_atom_links ...] etc.
    var inner = raw.replace(/^\s*\[\s*wpws(?:_atom_links|_atom_zip_links)?\s+/, '').replace(/\s*\]\s*$/, '');
    if (!inner) {
      return;
    }
    var attrs = {};
    var re = /(\w+)\s*=\s*["']([^"']*)["']/g;
    var m;
    while ((m = re.exec(inner)) !== null) {
      attrs[m[1].toLowerCase()] = m[2];
    }
    var url = attrs.url || '';
    var query = attrs.query || '';
    var argsParts = [];
    var skipKeys = { url: 1, query: 1 };
    Object.keys(attrs).sort().forEach(function(k) {
      if (!skipKeys[k]) {
        argsParts.push(k + '=' + attrs[k]);
      }
    });
    $('#url').val(url);
    $('#query').val(query);
    $('#args').val(argsParts.join('&'));
  });
});
