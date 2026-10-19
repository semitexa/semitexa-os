// Terminal app: run console skills by name. The skill list arrives in
// #terminal-skills, a JSON data block the handler fills.
var SKILLS = JSON.parse(document.getElementById('terminal-skills').textContent);
var out=document.getElementById('out'), cmd=document.getElementById('cmd'), chips=document.getElementById('chips');
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c]})}
SKILLS.forEach(function(s){var b=document.createElement('span');b.className='chip';b.textContent=s.name;b.title=s.summary||'';b.onclick=function(){cmd.value=s.name;cmd.focus()};chips.appendChild(b)});
function line(html){out.innerHTML+='\n'+html;out.scrollTop=out.scrollHeight}
async function run(name){
  name=(name||'').trim(); if(!name) return;
  line('<span class="cmd">&gt; '+esc(name)+'</span>');
  if(!SKILLS.some(function(s){return s.name===name})){ line('<span class="err">unknown console skill: '+esc(name)+'</span>'); return; }
  line('<span class="muted">running…</span>');
  try{
    var r=await fetch('/os/skill',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({intent:'(terminal) '+name,skill:name,arguments:{}})});
    var d=await r.json();
    var o=(d.output||'').replace(/\s+$/,'');
    line(o?esc(o):'<span class="muted">(no output)</span>');
    if(d.error) line('<span class="err">'+esc(d.error)+'</span>');
    line('<span class="muted">exit '+(d.exit_code==null?'?':d.exit_code)+'</span>');
  }catch(e){ line('<span class="err">'+esc(e.message)+'</span>'); }
}
cmd.addEventListener('keydown',function(e){ if(e.key==='Enter'){ var v=cmd.value; cmd.value=''; run(v); } });
