document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element) || !event.target.closest('[data-opotopo-add-place]')) return;
  const places = document.querySelector('[data-opotopo-places]');
  const template = places.querySelector('template');
  const index = places.querySelectorAll('[data-opotopo-place]').length;
  template.insertAdjacentHTML(
    'beforebegin',
    template.innerHTML.replaceAll('__index__', String(index)).replaceAll('__number__', String(index + 1)),
  );
});
