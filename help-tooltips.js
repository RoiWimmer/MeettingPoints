(function () {
  let bubble;
  let activeTrigger;
  let pinned = false;

  function ensureBubble() {
    if (bubble) return bubble;
    bubble = document.createElement('div');
    bubble.className = 'help-tooltip-bubble';
    bubble.id = 'helpTooltipBubble';
    bubble.setAttribute('role', 'tooltip');
    document.body.appendChild(bubble);
    return bubble;
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function positionBubble(trigger) {
    const tooltip = ensureBubble();
    const rect = trigger.getBoundingClientRect();
    const bubbleRect = tooltip.getBoundingClientRect();
    const margin = 12;
    const belowTop = rect.bottom + 10;
    const aboveTop = rect.top - bubbleRect.height - 10;
    const showAbove = belowTop + bubbleRect.height > window.innerHeight - margin && aboveTop >= margin;
    const top = clamp(showAbove ? aboveTop : belowTop, margin, window.innerHeight - bubbleRect.height - margin);
    const preferredLeft = rect.right - bubbleRect.width;
    const left = clamp(preferredLeft, margin, window.innerWidth - bubbleRect.width - margin);

    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
    tooltip.classList.toggle('is-above', showAbove);

    const triggerCenter = rect.left + (rect.width / 2);
    const arrow = clamp(left + bubbleRect.width - triggerCenter, 16, bubbleRect.width - 16);
    tooltip.style.setProperty('--help-arrow-right', `${arrow}px`);
  }

  function showTooltip(trigger, shouldPin = false) {
    const text = trigger.dataset.tooltip;
    if (!text) return;

    const tooltip = ensureBubble();
    activeTrigger?.classList.remove('is-open');
    activeTrigger?.removeAttribute('aria-describedby');

    activeTrigger = trigger;
    pinned = shouldPin;

    tooltip.textContent = text;
    trigger.classList.add('is-open');
    trigger.setAttribute('aria-describedby', tooltip.id);
    positionBubble(trigger);
    requestAnimationFrame(() => tooltip.classList.add('is-visible'));
  }

  function hideTooltip(force = false) {
    if (!bubble || (!force && pinned)) return;
    bubble.classList.remove('is-visible');
    activeTrigger?.classList.remove('is-open');
    activeTrigger?.removeAttribute('aria-describedby');
    activeTrigger = null;
    pinned = false;
  }

  function togglePinned(trigger) {
    if (activeTrigger === trigger && pinned) {
      hideTooltip(true);
      return;
    }

    showTooltip(trigger, true);
  }

  document.addEventListener('mouseenter', event => {
    const trigger = event.target.closest?.('.help-icon[data-tooltip]');
    if (trigger) showTooltip(trigger);
  }, true);

  document.addEventListener('mouseleave', event => {
    const trigger = event.target.closest?.('.help-icon[data-tooltip]');
    if (trigger && trigger === activeTrigger) hideTooltip();
  }, true);

  document.addEventListener('focusin', event => {
    const trigger = event.target.closest?.('.help-icon[data-tooltip]');
    if (trigger) showTooltip(trigger);
  });

  document.addEventListener('focusout', event => {
    const trigger = event.target.closest?.('.help-icon[data-tooltip]');
    if (trigger && trigger === activeTrigger) hideTooltip();
  });

  document.addEventListener('click', event => {
    const trigger = event.target.closest?.('.help-icon[data-tooltip]');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      togglePinned(trigger);
      return;
    }

    if (activeTrigger && !event.target.closest?.('.help-tooltip-bubble')) {
      hideTooltip(true);
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') hideTooltip(true);
  });

  window.addEventListener('resize', () => {
    if (activeTrigger) positionBubble(activeTrigger);
  });

  window.addEventListener('scroll', () => {
    if (activeTrigger) positionBubble(activeTrigger);
  }, true);
})();
