function validateDelay(delay) {
  if (!Number.isFinite(delay) || delay < 0) {
    throw new TypeError("delay must be a non-negative number");
  }
}

// Schedules from completion rather than from start, so a slow request can
// never overlap the next polling round.
export function createSerialPoller(callback, delay = 5000) {
  if (typeof callback !== "function") {
    throw new TypeError("callback must be a function");
  }
  validateDelay(delay);

  let active = false;
  let running = false;
  let timer = null;
  let generation = 0;
  let pendingImmediate = false;

  function clearTimer() {
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
  }

  function schedule(runGeneration) {
    if (!active || running || runGeneration !== generation) {
      return;
    }
    clearTimer();
    timer = setTimeout(() => {
      timer = null;
      execute(runGeneration);
    }, delay);
  }

  async function execute(runGeneration) {
    if (!active || running || runGeneration !== generation) {
      return false;
    }
    running = true;
    try {
      await callback();
    } catch (error) {
      // Transient failures are reflected by page state; polling continues.
    } finally {
      running = false;
      if (active) {
        if (pendingImmediate || runGeneration !== generation) {
          pendingImmediate = false;
          execute(generation);
        } else {
          schedule(runGeneration);
        }
      }
    }
    return true;
  }

  return {
    start() {
      if (active) {
        return Promise.resolve(false);
      }
      active = true;
      generation += 1;
      clearTimer();
      if (running) {
        pendingImmediate = true;
        return Promise.resolve(false);
      }
      return execute(generation);
    },
    stop() {
      active = false;
      generation += 1;
      pendingImmediate = false;
      clearTimer();
    },
    refresh() {
      if (!active) {
        return Promise.resolve(false);
      }
      clearTimer();
      if (running) {
        pendingImmediate = true;
        return Promise.resolve(false);
      }
      return execute(generation);
    },
    isRunning() {
      return running;
    },
    isStopped() {
      return !active;
    }
  };
}
