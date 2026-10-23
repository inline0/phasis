'use strict';
const chunks = [];
process.stdin.on('data', (chunk) => chunks.push(chunk));
process.stdin.on('end', () => {
  const output = { data: Buffer.concat(chunks).toString('hex'), argument: process.argv[2], module: require('path').basename(__filename) };
  process.stdout.write(JSON.stringify(output), () => process.exit(0));
});
