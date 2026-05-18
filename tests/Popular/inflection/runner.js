const I = Inflection;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("pluralize.cat", I.pluralize("cat"));
log("pluralize.child", I.pluralize("child"));
log("pluralize.person", I.pluralize("person"));
log("singularize.cats", I.singularize("cats"));
log("singularize.people", I.singularize("people"));

log("inflect.5.cats", I.inflect("cats", 5));
log("inflect.1.cat", I.inflect("cats", 1));

log("camelize.foo_bar", I.camelize("foo_bar"));
log("camelize.foo_bar_baz.no-first", I.camelize("foo_bar_baz", true));
log("underscore.FooBar", I.underscore("FooBarBaz"));
log("humanize.foo_bar", I.humanize("foo_bar_baz_id"));
log("capitalize.hello", I.capitalize("hello world"));
log("dasherize.foo_bar", I.dasherize("foo_bar_baz"));
log("titleize.man.house", I.titleize("man from the boondocks"));
log("demodulize.Foo::Bar", I.demodulize("Foo::Bar::Baz"));
log("tableize.PersonContact", I.tableize("PersonContact"));
log("classify.user_accounts", I.classify("user_accounts"));
log("foreignKey.Person", I.foreignKey("Person"));
log("ordinalize.1", I.ordinalize("1"));
log("ordinalize.2", I.ordinalize("2"));
log("ordinalize.3", I.ordinalize("3"));
log("ordinalize.11", I.ordinalize("11"));
log("ordinalize.22", I.ordinalize("22"));

console.log(out.join("\n"));
