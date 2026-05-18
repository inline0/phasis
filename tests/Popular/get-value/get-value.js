var GetvalueLib=(()=>{var j=Object.defineProperty;var _=Object.getOwnPropertyDescriptor;var w=Object.getOwnPropertyNames;var A=Object.prototype.hasOwnProperty;var C=(e,f)=>{for(var r in f)j(e,r,{get:f[r],enumerable:!0})},P=(e,f,r,d)=>{if(f&&typeof f=="object"||typeof f=="function")for(let l of w(f))!A.call(e,l)&&l!==r&&j(e,l,{get:()=>f[l],enumerable:!(d=_(f,l))||d.enumerable});return e};var S=e=>P(j({},"__esModule",{value:!0}),e);var q={};C(q,{default:()=>h});var I=Object.defineProperty,n=(e,f)=>I(e,"name",{value:f,configurable:!0}),x=n(e=>e!==null&&typeof e=="object","isObject"),V=n((e,f,r)=>typeof r.join=="function"?r.join(e):e[0]+f+e[1],"join"),k=n((e,f,r)=>typeof r.split=="function"?r.split(e):e.split(f),"split"),s=n((e,f={},r)=>typeof r?.isValid=="function"?r.isValid(e,f):!0,"isValid"),m=n(e=>x(e)||typeof e=="function","isValidObject"),o=n((e,f,r={})=>{if(x(r)||(r={default:r}),!m(e))return typeof r.default<"u"?r.default:e;typeof f=="number"&&(f=String(f));let d=Array.isArray(f),l=typeof f=="string",t=r.separator||".",v=r.joinChar||(typeof t=="string"?t:".");if(!l&&!d)return e;if(e[f]!==void 0)return s(f,e,r)?e[f]:r.default;let a=d?f:k(f,t,r),c=a.length,u=0;do{let i=a[u];for(typeof i!="string"&&(i=String(i));i&&i.slice(-1)==="\\";)i=V([i.slice(0,-1),a[++u]||""],v,r);if(e[i]!==void 0){if(!s(i,e,r))return r.default;e=e[i]}else{let b=!1,y=u+1;for(;y<c;)if(i=V([i,a[y++]],v,r),b=e[i]!==void 0){if(!s(i,e,r))return r.default;e=e[i],u=y-1;break}if(!b)return r.default}}while(++u<c&&m(e));return u===c?e:r.default},"getValue"),O=o;var h=O;return S(q);})();
/*! Bundled license information:

get-value/dist/index.mjs:
  (*!
   * get-value <https://github.com/jonschlinkert/get-value>
   *
   * Copyright (c) 2014-present, Jon Schlinkert.
   * Released under the MIT License.
   *)
*/
