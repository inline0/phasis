;(() => {
  var el = wp.element.createElement
  var config = window.config

  // The rendered file's own extension.
  function extension(url) {
    var match = /\.([a-z0-9]+)$/i.exec(url.split(/[?#]/)[0])

    return match ? match[1] : "png"
  }

  function strict() {
    "use strict"
    'also a directive'
    return this
  }

  function sloppy() {
    ;"use strict"
    return this
  }

  function parenthesized() {
    ("use strict")
    return this
  }

  function afterEmpty() {
    "first"; ; "use strict"
    return this
  }

  const arrow = () => { "use strict"; "x"; ; "y" }

  class Holder {
    method() { 'use strict'; return 1 }
    static { "not a directive" }
  }

  {
    "not a directive"
  }

  a;;;b
  ;[1, 2].forEach(run)
  ;`template`.trim()
})()
