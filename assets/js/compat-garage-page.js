(function(){'use strict';
var D=document,c=window.motoredCompatCfg||{},t=c.i18n||{},G=window.motoredGarageApi;
function mk(g,cl,tx){var e=D.createElement(g);if(cl)e.className=cl;if(tx!=null)e.textContent=tx;return e;}
function mu(sl,u){u=u||c.shopUrl||'/';return u+(u.indexOf('?')>-1?'&':'?')+'moto='+encodeURIComponent(sl);}
function run(pv,nx,mt,pt,bd){G.wr(nx);G.api(mt,pt,bd).catch(function(){G.wr(pv);});}
function drawG(){
var w=D.querySelector('.motored-garage-list');if(!w||!G)return;
var s=G.rd(),p=w.closest('[data-shop-url]'),sh=p&&p.dataset.shopUrl||c.shopUrl||'/';w.textContent='';
if(!s.bikes.length){w.appendChild(mk('p',0,t.emptyGarage||''));return;}
var ul=mk('ul');
s.bikes.forEach(function(bk){
var ia=s.active===bk.slug,li=mk('li',0,bk.label+(ia?' ['+(t.activeBadge||'')+'] ':' '));
if(!ia){var u=mk('button','button',t.useBike||'');u.type='button';u.onclick=function(){var pv=G.rd(),nx=G.rd();nx.active=bk.slug;run(pv,nx,'POST','/garage',{action:'set_active',slug:bk.slug});};li.appendChild(u);}
var v=mk('a','button',t.filterTires||'');v.href=mu(bk.slug,sh);li.appendChild(v);
var d=mk('button','button',t.remove||'');d.type='button';d.onclick=function(){var pv=G.rd(),nx=G.rd();nx.bikes=nx.bikes.filter(function(x){return x.slug!==bk.slug;});delete nx.profiles[bk.slug];if(nx.active===bk.slug)nx.active=nx.bikes[0]?nx.bikes[0].slug:'';run(pv,nx,'DELETE','/garage/'+encodeURIComponent(bk.slug));};li.appendChild(d);
ul.appendChild(li);
});
w.appendChild(ul);
}
D.addEventListener('motored:garage',drawG);
drawG();
})();
