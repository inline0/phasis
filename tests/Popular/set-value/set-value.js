var SetvalueLib=(()=>{var S=Object.create;var o=Object.defineProperty;var _=Object.getOwnPropertyDescriptor;var k=Object.getOwnPropertyNames;var q=Object.getPrototypeOf,M=Object.prototype.hasOwnProperty;var l=(e,r)=>()=>(r||e((r={exports:{}}).exports,r),r.exports),v=(e,r)=>{for(var t in r)o(e,t,{get:r[t],enumerable:!0})},m=(e,r,t,s)=>{if(r&&typeof r=="object"||typeof r=="function")for(let n of k(r))!M.call(e,n)&&n!==t&&o(e,n,{get:()=>r[n],enumerable:!(s=_(r,n))||s.enumerable});return e};var E=(e,r,t)=>(t=e!=null?S(q(e)):{},m(r||!e||!e.__esModule?o(t,"default",{value:e,enumerable:!0}):t,e)),N=e=>m(o({},"__esModule",{value:!0}),e);var d=l((J,b)=>{"use strict";b.exports=function(r){return typeof r=="object"?r===null:typeof r!="function"}});var h=l((L,g)=>{"use strict";g.exports=function(r){return r!=null&&typeof r=="object"&&Array.isArray(r)===!1}});var j=l((Q,P)=>{"use strict";var z=h();function O(e){return z(e)===!0&&Object.prototype.toString.call(e)==="[object Object]"}P.exports=function(r){var t,s;return!(O(r)===!1||(t=r.constructor,typeof t!="function")||(s=t.prototype,O(s)===!1)||s.hasOwnProperty("isPrototypeOf")===!1)}});var p=l((W,$)=>{"use strict";var{deleteProperty:C}=Reflect,I=d(),A=j(),w=e=>typeof e=="object"&&e!==null||typeof e=="function",R=e=>e==="__proto__"||e==="constructor"||e==="prototype",y=e=>{if(!I(e))throw new TypeError("Object keys must be strings or symbols");if(R(e))throw new Error(`Cannot set unsafe key: "${e}"`)},T=e=>Array.isArray(e)?e.flat().map(String).join(","):e,U=(e,r)=>{if(typeof e!="string"||!r)return e;let t=e+";";return r.arrays!==void 0&&(t+=`arrays=${r.arrays};`),r.separator!==void 0&&(t+=`separator=${r.separator};`),r.split!==void 0&&(t+=`split=${r.split};`),r.merge!==void 0&&(t+=`merge=${r.merge};`),r.preservePaths!==void 0&&(t+=`preservePaths=${r.preservePaths};`),t},V=(e,r,t)=>{let s=T(r?U(e,r):e);y(s);let n=u.cache.get(s)||t();return u.cache.set(s,n),n},B=(e,r={})=>{let t=r.separator||".",s=t==="/"?!1:r.preservePaths;if(typeof e=="string"&&s!==!1&&/\//.test(e))return[e];let n=[],c="",a=f=>{let i;f.trim()!==""&&Number.isInteger(i=Number(f))?n.push(i):n.push(f)};for(let f=0;f<e.length;f++){let i=e[f];if(i==="\\"){c+=e[++f];continue}if(i===t){a(c),c="";continue}c+=i}return c&&a(c),n},x=(e,r)=>r&&typeof r.split=="function"?r.split(e):typeof e=="symbol"?[e]:Array.isArray(e)?e:V(e,r,()=>B(e,r)),D=(e,r,t,s)=>{if(y(r),t===void 0)C(e,r);else if(s&&s.merge){let n=s.merge==="function"?s.merge:Object.assign;n&&A(e[r])&&A(t)?e[r]=n(e[r],t):e[r]=t}else e[r]=t;return e},u=(e,r,t,s)=>{if(!r||!w(e))return e;let n=x(r,s),c=e;for(let a=0;a<n.length;a++){let f=n[a],i=n[a+1];if(y(f),i===void 0){D(c,f,t,s);break}if(typeof i=="number"&&!Array.isArray(c[f])){c=c[f]=[];continue}w(c[f])||(c[f]={}),c=c[f]}return e};u.split=x;u.cache=new Map;u.clear=()=>{u.cache=new Map};$.exports=u});var G={};v(G,{default:()=>F});var K=E(p()),F=K.default;return N(G);})();
/*! Bundled license information:

is-primitive/index.js:
  (*!
   * is-primitive <https://github.com/jonschlinkert/is-primitive>
   *
   * Copyright (c) 2014-present, Jon Schlinkert.
   * Released under the MIT License.
   *)

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

set-value/index.js:
  (*!
   * set-value <https://github.com/jonschlinkert/set-value>
   *
   * Copyright (c) Jon Schlinkert (https://github.com/jonschlinkert).
   * Released under the MIT License.
   *)
*/
