switch (action.type) {
	case 'add': {
		const next = state + 1;
		return next;
	}
	case 'remove':
	case 'delete':
		return state - 1;
	default:
		return state;
}
