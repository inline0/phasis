// xstate — finite state machines. We build a small traffic-light
// machine plus a fetch-with-retry machine, drive a few transitions,
// and log the observable state at each step.
const { createMachine, createActor, assign } = XS.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Traffic light
const trafficLight = createMachine({
  id: "light",
  initial: "red",
  states: {
    red: { on: { TIMER: "green" } },
    green: { on: { TIMER: "yellow" } },
    yellow: { on: { TIMER: "red" } },
  },
});

const lightActor = createActor(trafficLight).start();
log("light.initial", lightActor.getSnapshot().value);
lightActor.send({ type: "TIMER" });
log("light.1", lightActor.getSnapshot().value);
lightActor.send({ type: "TIMER" });
log("light.2", lightActor.getSnapshot().value);
lightActor.send({ type: "TIMER" });
log("light.3", lightActor.getSnapshot().value);
lightActor.stop();

// Fetch machine with context + assign
const fetchMachine = createMachine({
  id: "fetch",
  initial: "idle",
  context: { retries: 0, data: null },
  states: {
    idle: { on: { FETCH: "loading" } },
    loading: {
      on: {
        RESOLVE: {
          target: "success",
          actions: assign({ data: ({ event }) => event.data }),
        },
        REJECT: {
          target: "failure",
          actions: assign({ retries: ({ context }) => context.retries + 1 }),
        },
      },
    },
    failure: {
      on: {
        RETRY: "loading",
      },
    },
    success: {
      type: "final",
    },
  },
});

const fetchActor = createActor(fetchMachine).start();
log("fetch.initial", fetchActor.getSnapshot().value);
fetchActor.send({ type: "FETCH" });
log("fetch.loading", fetchActor.getSnapshot().value);
fetchActor.send({ type: "REJECT" });
log("fetch.failure", fetchActor.getSnapshot().value);
log("fetch.retries", fetchActor.getSnapshot().context.retries);
fetchActor.send({ type: "RETRY" });
log("fetch.retry.loading", fetchActor.getSnapshot().value);
fetchActor.send({ type: "REJECT" });
log("fetch.retries2", fetchActor.getSnapshot().context.retries);
fetchActor.send({ type: "RETRY" });
fetchActor.send({ type: "RESOLVE", data: { items: [1, 2, 3] } });
log("fetch.success", fetchActor.getSnapshot().value);
log("fetch.data", fetchActor.getSnapshot().context.data);
log("fetch.status", fetchActor.getSnapshot().status);

console.log(out.join("\n"));
