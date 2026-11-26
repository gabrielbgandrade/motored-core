(function(){
var W='.motored-rp-field',w=document.querySelector(W),f;
if(!w&&window.motoredRP){
f=document.querySelector('#reviews #commentform,#reviews form.comment-form,#commentform,form.comment-form');
if(!f)return;
var s=f.querySelector('.form-submit'),h=document.createElement('div');
h.innerHTML=window.motoredRP;
if(s&&s.parentNode===f)f.insertBefore(h.firstChild,s);else f.appendChild(h.firstChild);
w=f.querySelector(W);
}
if(!w)return;
var i=w.querySelector('input'),m=w.querySelector('.motored-rp-msg'),t=w.querySelector('.motored-rp-thumbs'),
d=w.dataset,max=+d.max,mb=d.mb*1048576,u=[];
function resolveForm(){return i.form||i.closest('form')||w.closest('form');}
function setEnctype(){var form=resolveForm();if(form){form.enctype=form.encoding='multipart/form-data';form.setAttribute('enctype','multipart/form-data');f=form;}}
setEnctype();
var form=resolveForm();
if(form)form.addEventListener('submit',setEnctype);
i.addEventListener('change',function(){
setEnctype();
u.forEach(function(x){URL.revokeObjectURL(x)});u=[];t.textContent=m.textContent='';
var l=[].slice.call(i.files),e='';
if(l.length>max)e=d.eCount;
else if(l.some(function(x){return x.size>mb}))e=d.eSize;
else if(l.some(function(x){return x.type?!/^image\/(jpe?g|png|webp)$/i.test(x.type):!/\.(jpe?g|png|webp)$/i.test(x.name)}))e=d.eType;
if(e){m.textContent=e;i.value='';return}
l.forEach(function(x){var s=URL.createObjectURL(x),g=new Image();u.push(s);g.src=s;g.alt='';
g.style.cssText='width:64px;height:64px;object-fit:cover;margin:6px 6px 0 0;border-radius:4px';t.appendChild(g)});
});
})();
