// Tiny global store: logged-in user, public config and badge counters.

export const state = {
  me: null,
  config: null,
  counts: { notifications: 0, messages: 0, friend_requests: 0 },
};

const listeners = new Set();

export function setState(patch) {
  Object.assign(state, patch);
  listeners.forEach((fn) => {
    try {
      fn(state, patch);
    } catch (e) {
      console.error(e);
    }
  });
}

export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function updateMe(patch) {
  setState({ me: { ...state.me, ...patch } });
}
