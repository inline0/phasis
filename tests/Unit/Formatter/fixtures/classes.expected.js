class Point extends Base {
	static count = 0;
	#secret = 'hidden';
	constructor(x, y) {
		super();
		this.x = x;
		this.y = y;
	}
	get length() {
		return Math.sqrt(this.x ** 2 + this.y ** 2);
	}
	set length(v) {
		this.scale = v;
	}
	static create(...args) {
		return new Point(...args);
	}
	async *stream() {
		yield* this.items;
	}
	static {
		Point.count = 1;
	}
}
const Anon = class {
	method() {}
};
