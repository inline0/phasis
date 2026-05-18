var ExtendshallowLib=(()=>{var q=Object.create;var i=Object.defineProperty;var A=Object.getOwnPropertyDescriptor;var I=Object.getOwnPropertyNames;var T=Object.getPrototypeOf,C=Object.prototype.hasOwnProperty;var u=(t,r)=>()=>(r||t((r={exports:{}}).exports,r),r.exports),v=(t,r)=>{for(var e in r)i(t,e,{get:r[e],enumerable:!0})},l=(t,r,e,n)=>{if(r&&typeof r=="object"||typeof r=="function")for(let o of I(r))!C.call(t,o)&&o!==e&&i(t,o,{get:()=>r[o],enumerable:!(n=A(r,o))||n.enumerable});return t};var z=(t,r,e)=>(e=t!=null?q(T(t)):{},l(r||!t||!t.__esModule?i(e,"default",{value:t,enumerable:!0}):e,t)),B=t=>l(i({},"__esModule",{value:!0}),t);var b=u((U,y)=>{"use strict";y.exports=function(r){return r!=null&&typeof r=="object"&&Array.isArray(r)===!1}});var j=u((V,g)=>{"use strict";var D=b();function O(t){return D(t)===!0&&Object.prototype.toString.call(t)==="[object Object]"}g.exports=function(r){var e,n;return!(O(r)===!1||(e=r.constructor,typeof e!="function")||(n=e.prototype,O(n)===!1)||n.hasOwnProperty("isPrototypeOf")===!1)}});var d=u((W,m)=>{"use strict";var F=j();m.exports=function(r){return F(r)||typeof r=="function"||Array.isArray(r)}});var x=u((X,w)=>{"use strict";w.exports=function(t,r){if(t===null||typeof t>"u")throw new TypeError("expected first argument to be an object.");if(typeof r>"u"||typeof Symbol>"u"||typeof Object.getOwnPropertySymbols!="function")return t;for(var e=Object.prototype.propertyIsEnumerable,n=Object(t),o=arguments.length,a=0;++a<o;)for(var f=Object(arguments[a]),p=Object.getOwnPropertySymbols(f),s=0;s<p.length;s++){var c=p[s];e.call(f,c)&&(n[c]=f[c])}return n}});var P=u((Y,E)=>{"use strict";var G=d(),H=x();E.exports=Object.assign||function(t){if(t===null||typeof t>"u")throw new TypeError("Cannot convert undefined or null to object");h(t)||(t={});for(var r=1;r<arguments.length;r++){var e=arguments[r];K(e)&&(e=L(e)),h(e)&&(J(t,e),H(t,e))}return t};function J(t,r){for(var e in r)M(r,e)&&(t[e]=r[e])}function K(t){return t&&typeof t=="string"}function L(t){var r={};for(var e in t)r[e]=t[e];return r}function h(t){return t&&typeof t=="object"||G(t)}function M(t,r){return Object.prototype.hasOwnProperty.call(t,r)}});var Q={};v(Q,{default:()=>N});var S=z(P()),N=S.default;return B(Q);})();
/*! Bundled license information:

isobject/index.js:
  (*!
   * isobject <https://github.com/jonschlinkert/isobject>
   *
   * Copyright (c) 2014-2017, Jon Schlinkert.
   * Released under the MIT License.
   *)

is-plain-object/index.js:
  (*!
   * is-plain-object <https://github.com/jonschlinkert/is-plain-object>
   *
   * Copyright (c) 2014-2017, Jon Schlinkert.
   * Released under the MIT License.
   *)

is-extendable/index.js:
  (*!
   * is-extendable <https://github.com/jonschlinkert/is-extendable>
   *
   * Copyright (c) 2015-2017, Jon Schlinkert.
   * Released under the MIT License.
   *)

assign-symbols/index.js:
  (*!
   * assign-symbols <https://github.com/jonschlinkert/assign-symbols>
   *
   * Copyright (c) 2015, Jon Schlinkert.
   * Licensed under the MIT License.
   *)
*/
