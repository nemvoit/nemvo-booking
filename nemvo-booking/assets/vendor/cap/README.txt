Cap widget, bundled so that visitors' browsers load nothing from a CDN.

  cap.min.js           @cap.js/widget 0.1.63   Apache-2.0      LICENSE-cap.txt
  cap_wasm_bg.wasm     @cap.js/wasm 0.0.8      Apache-2.0      LICENSE-cap.txt
  hashwx.wasm          @cap.js/wasm 0.0.8      LGPL-3.0        LICENSE-hashwx.txt (+ LICENSE-gpl-3.0.txt)
  pako_inflate.min.js  pako 2.1.0              MIT and Zlib    LICENSE-pako.txt

The files are unmodified copies from the npm packages of the same names and versions.

Sources:

  cap.min.js
    Built from widget/src/src/cap.js and worker.js in https://github.com/tiagozip/cap
    at commit 18a975028e2de21acc9b3f99209439d3f09093e8 ("widget@0.1.63"):
    https://github.com/tiagozip/cap/tree/18a975028e2de21acc9b3f99209439d3f09093e8/widget/src
    (byte-identical to widget/src/cap.min.js in that commit)

  cap_wasm_bg.wasm
    Rust source in wasm/src/rust of https://github.com/tiagozip/cap
    at commit 8ad83fb50eaefb7f113ca1ec508ce242ed75033d ("wasm@0.0.8"):
    https://github.com/tiagozip/cap/tree/8ad83fb50eaefb7f113ca1ec508ce242ed75033d/wasm/src/rust

  hashwx.wasm
    HashWX by tevador, C source at https://github.com/tevador/hashwx
    commit 74b567a31276a4c5d7cb232a5b7639467f49f961, compiled to WebAssembly by the Cap project
    (published in wasm/src/browser at the tiagozip/cap commit above, and in core/vendor with
    the source commit in core/vendor/hashwx-COMMIT.txt). Licensed under the GNU LGPL 3.0,
    which builds on the GNU GPL 3.0; both texts are included.

  pako_inflate.min.js
    https://github.com/nodeca/pako/tree/2.1.0 (dist/pako_inflate.min.js)

Cap's upstream repository has no NOTICE file.

cap.min.js contains fallback URLs on cdn.jsdelivr.net for the WASM solvers and pako. They are
never used: the plugin sets window.CAP_CUSTOM_WASM_URL, window.CAP_CUSTOM_HASHWX_URL and
window.CAP_PAKO_URL to the copies in this folder before the widget loads.
pako is only loaded by browsers without DecompressionStream.

The plugin also sets window.CAP_DISABLE_WIDGET_REF, and gives every widget a fixed
data-cap-troubleshooting-url (assets/js/frontend.js), so the widget adds no page address,
referrer or browser details to its "Cap" credit and "Troubleshoot" links. It sets
window.CAP_ISOLATE as well, so the instrumentation frame runs with an opaque origin and the
script from the Cap server can't read or change the page.
