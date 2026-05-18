// mqtt-packet — generate + parse MQTT 3.1.1 / 5.0 control packets.
// Pure-JS, browser-friendly. Buffer is provided by esbuild's polyfill
// (the bundle inlines its own Buffer shim), so we can serialize to a
// Uint8Array, dump the bytes, then parse them back.
const mp = MqttPacket.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Helper: emit the hex bytes so any drift in packet layout is
// byte-visible in the oracle.
function hex(buf) {
  const bytes = [];
  for (let i = 0; i < buf.length; i++) {
    bytes.push(buf[i].toString(16).padStart(2, "0"));
  }
  return bytes.join("");
}

// 1. CONNECT
const connect = mp.generate({
  cmd: "connect",
  protocolId: "MQTT",
  protocolVersion: 4,
  clean: true,
  clientId: "phasis-client",
  keepalive: 60,
});
log("connect.hex", hex(connect));
log("connect.len", connect.length);

// 2. PUBLISH (QoS 0)
const pubQ0 = mp.generate({
  cmd: "publish",
  retain: false,
  qos: 0,
  dup: false,
  topic: "sensors/temp",
  payload: "21.5",
});
log("pubQ0.hex", hex(pubQ0));

// 3. PUBLISH (QoS 1, with messageId)
const pubQ1 = mp.generate({
  cmd: "publish",
  retain: false,
  qos: 1,
  dup: false,
  topic: "sensors/temp",
  payload: "21.5",
  messageId: 42,
});
log("pubQ1.hex", hex(pubQ1));

// 4. SUBSCRIBE
const sub = mp.generate({
  cmd: "subscribe",
  messageId: 100,
  subscriptions: [
    { topic: "sensors/+", qos: 1 },
    { topic: "alerts/#", qos: 2 },
  ],
});
log("sub.hex", hex(sub));

// 5. PINGREQ
const ping = mp.generate({ cmd: "pingreq" });
log("ping.hex", hex(ping));

// 6. DISCONNECT
const disc = mp.generate({ cmd: "disconnect" });
log("disc.hex", hex(disc));

// 7. Round-trip via parser
const parser = mp.parser();
const parsed = [];
parser.on("packet", (pkt) => parsed.push(pkt));

parser.parse(connect);
parser.parse(pubQ0);
parser.parse(pubQ1);
parser.parse(sub);
parser.parse(ping);
parser.parse(disc);

log("parsed.count", parsed.length);
log("p0.cmd", parsed[0] && parsed[0].cmd);
log("p0.clientId", parsed[0] && parsed[0].clientId);
log("p1.cmd", parsed[1] && parsed[1].cmd);
log("p1.topic", parsed[1] && parsed[1].topic);
log("p1.payload", parsed[1] && parsed[1].payload && parsed[1].payload.toString());
log("p2.qos", parsed[2] && parsed[2].qos);
log("p2.messageId", parsed[2] && parsed[2].messageId);
log("p3.cmd", parsed[3] && parsed[3].cmd);
log("p3.subs", parsed[3] && parsed[3].subscriptions);
log("p4.cmd", parsed[4] && parsed[4].cmd);
log("p5.cmd", parsed[5] && parsed[5].cmd);

console.log(out.join("\n"));
