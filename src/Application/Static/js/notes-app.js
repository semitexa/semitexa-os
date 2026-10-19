// Notes app: one textarea kept in localStorage on this device.
var t=document.getElementById('n'), s=document.getElementById('saved'), K='semitexa-os-notes', tm;
t.value=localStorage.getItem(K)||'';
t.addEventListener('input',function(){
  localStorage.setItem(K,t.value);
  s.classList.add('show'); clearTimeout(tm); tm=setTimeout(function(){s.classList.remove('show')},900);
});
t.focus();
