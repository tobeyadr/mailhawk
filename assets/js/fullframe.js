( function ($) {

  var matched, Browser

  $.uaMatch = function (ua) {
    ua = ua.toLowerCase()

    var match = /(chrome)[ \/]([\w.]+)/.exec(ua) ||
      /(webkit)[ \/]([\w.]+)/.exec(ua) ||
      /(opera)(?:.*version|)[ \/]([\w.]+)/.exec(ua) ||
      /(msie) ([\w.]+)/.exec(ua) ||
      ua.indexOf('compatible') < 0 &&
      /(mozilla)(?:.*? rv:([\w.]+)|)/.exec(ua) ||
      []

    return {
      Browser: match[1] || '',
      version: match[2] || '0',
    }
  }

  matched = $.uaMatch(navigator.userAgent)
  Browser = {}

  if (matched.Browser) {
    Browser[matched.Browser] = true
    Browser.version = matched.version
  }

  // Chrome is Webkit, but Webkit is also Safari.
  if (Browser.chrome) {
    Browser.webkit = true
  }
  else if (Browser.webkit) {
    Browser.safari = true
  }

  function initAllFrames () {
    var iFrames = $('iframe')

    var offset = 0

    function iResize () {
      for (var i = 0, j = iFrames.length; i < j; i++) {
        iFrames[i].height($(iFrames[i].contentWindow.document).height())
      }
    }

    if (Browser.safari || Browser.opera) {

      iFrames.on('load', function () {
        setTimeout(iResize, 0)
      })

      for (var i = 0, j = iFrames.length; i < j; i++) {
        var iSource = iFrames[i].src
        iFrames[i].src = ''
        iFrames[i].src = iSource
      }

    }
    else {
      iFrames.on('load', function () {
        $(this).height($(this.contentWindow.document).height())
      })
    }
  }

  function addEvent (event, callback) {
    if (!window.addEventListener) { // This listener will not be valid in < IE9
      window.attachEvent('on' + event, callback)
    }
    else { // For all other Browsers other than < IE9
      window.addEventListener(event, callback, false)
    }
  }

  function resizeAllFrames () {
    var iFrames = $('iframe')
    for (var i = 0; i < iFrames.length; i++) {
      var ifrm = iFrames[i]
      var $ifrm = $(ifrm)
      $ifrm.attr('id', 'frame-' + ( i + 1 ))
      var height = ifrm.contentWindow.postMessage(
        { action: 'getFrameSize', id: $ifrm.attr('id') }, '*')
    }
  }

  function receiveMessage (event) {
    // console.log( event.data );
    resizeFrame(event.data)
  }

  function resizeFrame (data) {
    if (data.height) {
      var f = $('#' + data.id)
      if (f) {
        f.height(data.height)
        f.width(data.width)
      }
    }
  }

  addEvent('message', receiveMessage)
  addEvent('resize', resizeAllFrames)
  addEvent('load', resizeAllFrames)

  initAllFrames()

  $.fullFrame = function () {
    initAllFrames()
    return true
  }

} )(jQuery)
