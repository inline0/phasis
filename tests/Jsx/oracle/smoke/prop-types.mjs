import PropTypes from "prop-types";
const errors = [];
const original = console.error;
console.error = (m) => errors.push(String(m).split("\n")[0]);
PropTypes.checkPropTypes({ name: PropTypes.string.isRequired }, { name: 1 }, "prop", "Card");
console.error = original;
console.log(typeof PropTypes.string, errors.length);
