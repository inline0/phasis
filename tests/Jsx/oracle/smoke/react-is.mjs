import { isElement, isValidElementType, Fragment, typeOf } from "react-is";
const el = { $$typeof: Symbol.for("react.transitional.element"), type: "div", props: {} };
console.log(isElement(el), isValidElementType("div"), isValidElementType(42), typeOf(el) === Symbol.for("react.transitional.element"), String(Fragment));
