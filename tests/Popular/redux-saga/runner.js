// redux-saga — generator-based effect manager. Exercises async
// generators + channel-based dispatch heavily.
const { runSaga, stdChannel, take, put, call, fork, all } = SagaBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const channel = stdChannel();
const dispatched = [];
const state = { count: 0 };

const io = {
  channel,
  dispatch: (action) => {
    dispatched.push(action);
    channel.put(action);
  },
  getState: () => state,
};

function* incrementSaga() {
  while (true) {
    const action = yield take("INCREMENT");
    state.count += action.payload || 1;
    yield put({ type: "INCREMENTED", count: state.count });
  }
}

function* delayCall(ms) {
  return new Promise((resolve) => setTimeout(() => resolve("done"), ms));
}

function* sumWorker() {
  let total = 0;
  for (let i = 0; i < 5; i++) {
    const a = yield take("ADD");
    total += a.payload;
  }
  yield put({ type: "SUM_RESULT", total });
}

async function run() {
  try {
    // Start increment saga
    const inc = runSaga(io, incrementSaga);
    io.dispatch({ type: "INCREMENT", payload: 5 });
    io.dispatch({ type: "INCREMENT", payload: 3 });
    io.dispatch({ type: "INCREMENT", payload: 2 });

    // Drain microtasks
    await new Promise((r) => setTimeout(r, 0));

    log("after.inc.count", state.count);

    inc.cancel();

    // Start sum saga and dispatch several ADD actions
    const sumTask = runSaga(io, sumWorker);
    for (let i = 1; i <= 5; i++) {
      io.dispatch({ type: "ADD", payload: i });
    }
    await new Promise((r) => setTimeout(r, 0));

    const incrementedActions = dispatched.filter((a) => a.type === "INCREMENTED");
    log("incremented.count", incrementedActions.length);
    log("incremented.last", incrementedActions[incrementedActions.length - 1]);

    const sumResult = dispatched.find((a) => a.type === "SUM_RESULT");
    log("sum.result", sumResult);
  } catch (e) {
    log("error", e.message || String(e));
  }
  console.log(out.join("\n"));
}

run();
