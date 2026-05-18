// graphql-js — parse, validate, execute against a small schema.
const {
  buildSchema, parse, validate, execute, graphqlSync,
  GraphQLObjectType, GraphQLString, GraphQLInt, GraphQLSchema, GraphQLList,
} = GraphqlJs;

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Build a schema from SDL (no `!` non-null markers — Phasis hits
// an internal graphql validator quirk around required-arg + isDeprecated)
const schema = buildSchema(`
  type User { id: Int, name: String, role: String }
  type Query {
    user(id: Int): User
    users: [User]
    hello(name: String): String
  }
`);

const root = {
  hello: ({ name }) => "Hello, " + (name || "world") + "!",
  user: ({ id }) => ({ id, name: "Alice", role: "admin" }),
  users: () => [
    { id: 1, name: "Alice", role: "admin" },
    { id: 2, name: "Bob", role: "user" },
  ],
};

// 2. graphqlSync — runs parse + validate + execute end-to-end
const r1 = graphqlSync({ schema, source: "{ hello(name: \"Phasis\") }", rootValue: root });
log("hello", r1);

const r2 = graphqlSync({ schema, source: "{ users { id name } }", rootValue: root });
log("users", r2);

const r3 = graphqlSync({ schema, source: "{ user(id: 1) { name role } }", rootValue: root });
log("user", r3);

// 3. Validation: ask for unknown field
const r4 = graphqlSync({ schema, source: "{ missingField }", rootValue: root });
log("invalid", { errorCount: r4.errors ? r4.errors.length : 0, message: r4.errors && r4.errors[0] && r4.errors[0].message });

// 4. parse + manual validate
const doc = parse("{ users { id } }");
const errs = validate(schema, doc);
log("parse+validate", { errCount: errs.length });

// 5. Build a schema programmatically
const ManualSchema = new GraphQLSchema({
  query: new GraphQLObjectType({
    name: "Query",
    fields: { x: { type: GraphQLInt, resolve: () => 42 } },
  }),
});
const r5 = graphqlSync({ schema: ManualSchema, source: "{ x }" });
log("manual", r5);

console.log(out.join("\n"));
