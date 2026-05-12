/* CloudScale Crash Recovery — 404 Olympics v2 */
(function(){
'use strict';
var c=document.getElementById('cs404-game');
if(!c||!c.getContext)return;
if(!CanvasRenderingContext2D.prototype.roundRect){
    CanvasRenderingContext2D.prototype.roundRect=function(x,y,w,h,r){
        r=Math.min(r,w/2,h/2);
        this.moveTo(x+r,y);this.lineTo(x+w-r,y);this.arcTo(x+w,y,x+w,y+r,r);
        this.lineTo(x+w,y+h-r);this.arcTo(x+w,y+h,x+w-r,y+h,r);
        this.lineTo(x+r,y+h);this.arcTo(x,y+h,x,y+h-r,r);
        this.lineTo(x,y+r);this.arcTo(x,y,x+r,y,r);this.closePath();
    };
}
var ctx=c.getContext('2d'),W=c.width,H=c.height;

/* ── Per-game leaderboards (top 10) ─────────────── */
var GNAMES=['runner','jetpack','racer','miner','asteroids','snake','spaceinvaders'];
var lbData={};
GNAMES.forEach(function(g){
    var raw=localStorage.getItem('cs404_lb_'+g);
    lbData[g]=raw?JSON.parse(raw):[];
});
function lbInsert(game,score,name){
    if(!score||score<=0)return false;
    var lb=lbData[game];
    if(lb.length>=10&&score<=lb[9].s)return false;
    // Prevent exact duplicate {score, name} entries
    var n=name||'';
    for(var i=0;i<lb.length;i++){if(lb[i].s===score&&lb[i].n===n)return false;}
    lb.push({s:score,n:n});
    lb.sort(function(a,b){return b.s-a.s;});
    if(lb.length>10)lb.length=10;
    localStorage.setItem('cs404_lb_'+game,JSON.stringify(lb));
    return true;
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function renderLeaderboard(game){
    var panel=document.getElementById('cs404-lb-body');
    var title=document.getElementById('cs404-lb-title');
    if(!panel)return;
    var gname={runner:'Runner',jetpack:'Jetpack',racer:'Racer',miner:'Miner',asteroids:'Asteroids',snake:'Snake',spaceinvaders:'Space Invaders'};
    if(title)title.textContent='\uD83C\uDFC6 '+(gname[game]||game)+' \u2014 Top 10';
    var lb=lbData[game];
    if(!lb||lb.length===0){panel.innerHTML='<p class="cs404-lb-empty">No scores yet \u2014 be the first!</p>';return;}
    var html='',medals=['\uD83E\uDD47','\uD83E\uDD48','\uD83E\uDD49'];
    for(var i=0;i<lb.length;i++){
        var medal=i<3?medals[i]:(i+1)+'.';
        html+='<div class="cs404-lb-row'+(i===0?' cs404-lb-row-gold':'')+'">'+
            '<span class="cs404-lb-rank">'+medal+'</span>'+
            '<span class="cs404-lb-name">'+escHtml(lb[i].n||'Anonymous')+'</span>'+
            '<span class="cs404-lb-score">'+String(lb[i].s).padStart(6,'0')+'</span>'+
            '</div>';
    }
    panel.innerHTML=html;
}
var _s=document.querySelector('script[data-api]');
var CS_PCR_API=_s?_s.getAttribute('data-api'):undefined;
var CS_PCR_SCORE_NONCE=_s?_s.getAttribute('data-nonce'):undefined;
if(CS_PCR_API){
    GNAMES.forEach(function(g){
        fetch(CS_PCR_API+'/hiscore/'+g)
            .then(function(r){return r.json();})
            .then(function(d){
                if(d.leaderboard&&Array.isArray(d.leaderboard)){
                    // Server is always authoritative — replace local even if empty
                    lbData[g]=d.leaderboard.map(function(e){return{s:e.score,n:e.name};});
                    localStorage.setItem('cs404_lb_'+g,JSON.stringify(lbData[g]));
                    renderLeaderboard(currentGame);
                }
            })
            .catch(function(){});
    });
}

/* ── Name overlay ───────────────────────────────── */
var namePending=false,pendingGame='runner',pendingScore=0;
var saveBtn=document.getElementById('cs404-name-save');
var nameInput=document.getElementById('cs404-name-input');
var nameOverlay=document.getElementById('cs404-name-overlay');
function saveName(){
    var n=(nameInput?nameInput.value.trim():'')||'Anonymous';
    var g=pendingGame,s=pendingScore;
    lbInsert(g,s,n);
    renderLeaderboard(g);
    if(CS_PCR_API){
        fetch(CS_PCR_API+'/hiscore/'+g,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Score-Nonce':CS_PCR_SCORE_NONCE||''},
            body:JSON.stringify({game:g,score:s,name:n})})
            .then(function(r){return r.json();})
            .then(function(d){
                if(d.leaderboard&&Array.isArray(d.leaderboard)){
                    lbData[g]=d.leaderboard.map(function(e){return{s:e.score,n:e.name};});
                    localStorage.setItem('cs404_lb_'+g,JSON.stringify(lbData[g]));
                    renderLeaderboard(g);
                }
            })
            .catch(function(){});
    }
    if(nameOverlay)nameOverlay.style.display='none';
    if(nameInput)nameInput.value='';
    namePending=false;
}
if(saveBtn)saveBtn.addEventListener('click',saveName);
if(nameInput)nameInput.addEventListener('keydown',function(e){if(e.key==='Enter')saveName();});

function checkNewHi(game,score){
    if(score<=0)return 0;
    var lb=lbData[game];
    var qualifies=lb.length<10||score>lb[lb.length-1].s;
    if(!qualifies)return 0;
    var rank=lb.length+1;
    for(var i=0;i<lb.length;i++){if(score>lb[i].s){rank=i+1;break;}}
    pendingScore=score;pendingGame=game;namePending=true;
    var hd=document.querySelector('#cs404-name-overlay p:first-child');
    if(hd)hd.textContent=rank===1?'\uD83C\uDFC6 New Record!':'\uD83C\uDFC6 Top 10 Entry!';
    burstFireworks();
    setTimeout(function(){if(nameOverlay)nameOverlay.style.display='flex';if(nameInput)nameInput.focus();},700);
    return rank;
}

/* ── Particles ──────────────────────────────────── */
var particles=[];
var FW=['#f57c00','#ffa726','#ff9800','#ffcc02','#ff6b6b','#a78bfa','#34d399','#60a5fa'];
function burstFireworks(){
    for(var b=0;b<6;b++){
        var bx=W*0.2+Math.random()*W*0.6,by=H*0.15+Math.random()*H*0.5;
        for(var p=0;p<22;p++){
            var a=Math.random()*Math.PI*2,sp=2+Math.random()*4;
            particles.push({x:bx,y:by,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp,life:1,dec:0.018+Math.random()*0.012,r:3+Math.random()*3,col:FW[Math.floor(Math.random()*FW.length)]});
        }
    }
}
function updateParticles(){
    for(var i=particles.length-1;i>=0;i--){
        var p=particles[i];p.x+=p.vx;p.y+=p.vy;p.vy+=0.08;p.life-=p.dec;
        if(p.life<=0)particles.splice(i,1);
    }
}
function drawParticles(){
    for(var i=0;i<particles.length;i++){
        var p=particles[i];ctx.globalAlpha=p.life;ctx.fillStyle=p.col;
        ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fill();
    }
    ctx.globalAlpha=1;
}

/* ── Shared overlays ────────────────────────────── */
function drawHiPanel(game){
    var lb=lbData[game];
    ctx.save();ctx.font='bold 12px monospace';
    if(lb.length>0&&lb[0].s>0){
        ctx.fillStyle='#f57c00';ctx.textAlign='left';
        ctx.fillText('\uD83C\uDFC6 '+(lb[0].n||'Anonymous')+' \u2014 '+lb[0].s,10,18);
    }
    ctx.restore();
}
function drawScore(score){
    ctx.save();ctx.font='bold 12px monospace';ctx.fillStyle='#0d2a4a';ctx.textAlign='right';
    ctx.fillText(String(score).padStart(6,'0'),W-10,18);ctx.restore();
}
function drawGameOver(score,rank){
    var isNew=rank>0;
    var bh=isNew?86:62;
    ctx.fillStyle='rgba(204,233,251,0.92)';
    ctx.beginPath();ctx.roundRect(W/2-125,H/2-34,250,bh,8);ctx.fill();
    ctx.strokeStyle=isNew?'rgba(245,124,0,0.5)':'rgba(42,96,144,0.3)';ctx.lineWidth=1.5;
    ctx.beginPath();ctx.roundRect(W/2-125,H/2-34,250,bh,8);ctx.stroke();
    ctx.textAlign='center';
    ctx.fillStyle='#0d2a4a';ctx.font='bold 15px monospace';ctx.fillText('GAME OVER',W/2,H/2-14);
    ctx.font='12px monospace';ctx.fillStyle='#6b7280';ctx.fillText('Score: '+score,W/2,H/2+6);
    if(isNew){
        var msg=rank===1?'\uD83C\uDFC6 NEW RECORD!':'\uD83C\uDFC6 TOP 10 (#'+rank+')!';
        ctx.fillStyle='#f57c00';ctx.font='bold 13px monospace';ctx.fillText(msg,W/2,H/2+26);
        ctx.font='10px monospace';ctx.fillStyle='#3a6080';ctx.fillText('SPACE or TAP to retry',W/2,H/2+46);
    } else {
        ctx.font='10px monospace';ctx.fillStyle='#3a6080';ctx.fillText('SPACE or TAP to retry',W/2,H/2+26);
    }
}
function drawWelcome(title,sub){
    ctx.save();ctx.textAlign='center';
    var lb=lbData[currentGame];
    var rows=Math.min(lb.length,10);
    if(rows>0){
        var bh=48+rows*15+24;
        var by=Math.max(22,H/2-bh/2);
        ctx.fillStyle='rgba(13,42,74,0.84)';
        ctx.beginPath();ctx.roundRect(W/2-195,by,390,bh,8);ctx.fill();
        ctx.strokeStyle='rgba(245,124,0,0.25)';ctx.lineWidth=1;
        ctx.beginPath();ctx.roundRect(W/2-195,by,390,bh,8);ctx.stroke();
        // header
        ctx.fillStyle='#f59e0b';ctx.font='bold 12px monospace';
        ctx.fillText('\uD83C\uDFC6 '+title+' \u2014 Leaderboard',W/2,by+15);
        ctx.fillStyle='rgba(204,233,251,0.6)';ctx.font='9px monospace';
        ctx.fillText(sub,W/2,by+28);
        // divider
        ctx.strokeStyle='rgba(245,124,0,0.2)';ctx.lineWidth=1;
        ctx.beginPath();ctx.moveTo(W/2-175,by+35);ctx.lineTo(W/2+175,by+35);ctx.stroke();
        // entries
        var medals=['\uD83E\uDD47','\uD83E\uDD48','\uD83E\uDD49'];
        for(var i=0;i<rows;i++){
            var ey=by+48+i*15;
            var medal=i<3?medals[i]:(i+1)+'.';
            ctx.font=(i===0?'bold ':'')+'10px monospace';
            ctx.fillStyle=i===0?'#fbbf24':i<3?'#e2e8f0':'#94a3b8';
            ctx.textAlign='left';
            ctx.fillText(medal+' '+(lb[i].n||'Anonymous').substring(0,18),W/2-180,ey);
            ctx.textAlign='right';
            ctx.fillText(String(lb[i].s).padStart(6,'0'),W/2+180,ey);
        }
        // play prompt
        ctx.textAlign='center';
        ctx.fillStyle='#f57c00';ctx.font='bold 12px monospace';
        ctx.fillText('SPACE or TAP to play',W/2,by+bh-8);
    } else {
        ctx.fillStyle='rgba(13,42,74,0.72)';ctx.beginPath();ctx.roundRect(W/2-150,H/2-44,300,88,8);ctx.fill();
        ctx.fillStyle='#fff';ctx.font='bold 16px monospace';ctx.fillText(title,W/2,H/2-16);
        ctx.fillStyle='#cce9fb';ctx.font='11px monospace';ctx.fillText(sub,W/2,H/2+6);
        ctx.fillStyle='#f57c00';ctx.font='bold 13px monospace';ctx.fillText('SPACE or TAP to play',W/2,H/2+28);
    }
    ctx.restore();
}

/* ── Current game ───────────────────────────────── */
var currentGame='runner';

/* ═══════════════════════════════════════════════
   GAME 1 — RUNNER
   ═══════════════════════════════════════════════ */
var GY=H-28;
var RN={run:false,over:false,score:0,fr:0,spd:4,
    px:80,py:0,pw:26,ph:30,vy:0,grnd:true,jmps:0,
    obs:[],holes:[],rockets:[],shooters:[],nObs:20,nShoot:160,
    clouds:[{x:120,y:30,w:70},{x:320,y:26,w:55},{x:500,y:34,w:65}],newHi:false};
RN.py=GY-RN.ph;
function rnReset(){
    RN.py=GY-RN.ph;RN.vy=0;RN.grnd=true;RN.jmps=0;
    RN.obs=[];RN.holes=[];RN.rockets=[];RN.shooters=[];particles=[];
    RN.score=0;RN.fr=0;RN.spd=4;RN.nObs=20;RN.nShoot=160;
    RN.newHi=false;namePending=false;
    if(nameOverlay)nameOverlay.style.display='none';
    RN.run=true;RN.over=false;
}
function rnJump(){
    if(namePending)return;
    if(!RN.run&&!RN.over){rnReset();return;}
    if(RN.over){rnReset();return;}
    if(RN.jmps<2){RN.vy=-9;RN.grnd=false;RN.jmps++;}
}
function rnOverHole(){
    for(var i=0;i<RN.holes.length;i++){
        if(RN.px+RN.pw-6>RN.holes[i].x+2&&RN.px+6<RN.holes[i].x+RN.holes[i].w-2)return true;
    }
    return false;
}
function rnDie(){
    if(RN.over)return;RN.over=true;RN.run=false;RN.newHi=checkNewHi('runner',RN.score);
}
function rnUpdate(){
    if(!RN.run||RN.over)return;
    RN.fr++;RN.score++;RN.spd=4+Math.floor(RN.score/300)*0.4;
    RN.vy+=0.55;RN.py+=RN.vy;
    if(RN.py>=GY-RN.ph){if(!rnOverHole()){RN.py=GY-RN.ph;RN.vy=0;RN.grnd=true;RN.jmps=0;}else RN.grnd=false;}
    if(RN.py>H+20){rnDie();return;}
    for(var i=0;i<RN.clouds.length;i++){
        RN.clouds[i].x-=RN.spd*0.3;
        if(RN.clouds[i].x+RN.clouds[i].w<0)RN.clouds[i].x=W+RN.clouds[i].w;
    }
    RN.nShoot--;
    if(RN.nShoot<=0&&RN.shooters.length===0){
        RN.shooters.push({x:W-60,y:GY-44,w:24,h:44,rt:55,ri:90,fl:0,life:320+Math.floor(Math.random()*120)});
        RN.nShoot=180+Math.floor(Math.random()*120);
    }
    for(var i=RN.shooters.length-1;i>=0;i--){
        var s=RN.shooters[i];if(s.fl>0)s.fl--;s.rt--;
        if(s.rt<=0){RN.rockets.push({x:s.x-28,y:GY-20,w:28,h:12,spd:RN.spd+3});s.fl=12;s.rt=s.ri+Math.floor(Math.random()*40);}
        s.life--;
        if(s.life<=0){RN.shooters.splice(i,1);RN.nShoot=180+Math.floor(Math.random()*120);continue;}
        if(RN.px+RN.pw-4>s.x+3&&RN.px+4<s.x+s.w-3&&RN.py+RN.ph>s.y+3&&RN.py<s.y+s.h){rnDie();return;}
    }
    for(var i=RN.rockets.length-1;i>=0;i--){
        RN.rockets[i].x-=RN.rockets[i].spd;
        if(RN.rockets[i].x+RN.rockets[i].w<0){RN.rockets.splice(i,1);continue;}
        if(RN.px+RN.pw-4>RN.rockets[i].x+4&&RN.px+4<RN.rockets[i].x+RN.rockets[i].w-4&&RN.py+RN.ph>RN.rockets[i].y+2&&RN.py<RN.rockets[i].y+RN.rockets[i].h){rnDie();return;}
    }
    RN.nObs--;
    if(RN.nObs<=0){
        var r=Math.random();
        if(r<0.22){RN.holes.push({x:W,w:32+Math.floor(Math.random()*22)});RN.nObs=50+Math.floor(Math.random()*40);}
        else if(r<0.42){RN.obs.push({type:'water',x:W,y:GY-12,w:38+Math.floor(Math.random()*26),h:12});RN.nObs=40+Math.floor(Math.random()*35);}
        else if(r<0.60){RN.obs.push({type:'block',x:W,y:GY-58,w:28,h:58});RN.nObs=50+Math.floor(Math.random()*40);}
        else{var h=24+Math.floor(Math.random()*24);RN.obs.push({type:'block',x:W,y:GY-h,w:30,h:h});RN.nObs=40+Math.floor(Math.random()*45);}
    }
    for(var j=RN.holes.length-1;j>=0;j--){RN.holes[j].x-=RN.spd;if(RN.holes[j].x+RN.holes[j].w<0)RN.holes.splice(j,1);}
    for(var j=RN.obs.length-1;j>=0;j--){
        RN.obs[j].x-=RN.spd;
        if(RN.obs[j].x+RN.obs[j].w<0){RN.obs.splice(j,1);continue;}
        if(RN.px+RN.pw-5>RN.obs[j].x+4&&RN.px+5<RN.obs[j].x+RN.obs[j].w-4&&RN.py+RN.ph>RN.obs[j].y+3&&RN.py<RN.obs[j].y+RN.obs[j].h){rnDie();return;}
    }
}
function rnDraw(){
    ctx.clearRect(0,0,W,H);
    for(var i=0;i<RN.clouds.length;i++){
        var cl=RN.clouds[i];ctx.fillStyle='rgba(255,255,255,0.55)';ctx.beginPath();
        ctx.ellipse(cl.x,cl.y,cl.w/2,12,0,0,Math.PI*2);ctx.ellipse(cl.x-cl.w/4,cl.y+4,cl.w/4,9,0,0,Math.PI*2);ctx.ellipse(cl.x+cl.w/4,cl.y+4,cl.w/4,9,0,0,Math.PI*2);ctx.fill();
    }
    var segs=RN.holes.slice().sort(function(a,b){return a.x-b.x;});
    var sx=0;ctx.fillStyle='rgba(42,96,144,0.35)';
    for(var i=0;i<segs.length;i++){if(segs[i].x>sx)ctx.fillRect(sx,GY,segs[i].x-sx,H-GY);sx=segs[i].x+segs[i].w;}
    if(sx<W)ctx.fillRect(sx,GY,W-sx,H-GY);
    sx=0;ctx.fillStyle='#2a6090';
    for(var i=0;i<segs.length;i++){if(segs[i].x>sx)ctx.fillRect(sx,GY,segs[i].x-sx,3);sx=segs[i].x+segs[i].w;}
    if(sx<W)ctx.fillRect(sx,GY,W-sx,3);
    drawHiPanel('runner');drawScore(RN.score);
    if(RN.run||RN.over){
        for(var j=0;j<RN.obs.length;j++){
            var o=RN.obs[j];
            if(o.type==='water'){
                ctx.fillStyle='rgba(30,120,200,0.6)';ctx.beginPath();ctx.roundRect(o.x,o.y,o.w,o.h,3);ctx.fill();
                ctx.strokeStyle='rgba(160,220,255,0.8)';ctx.lineWidth=1.5;
                for(var wx=o.x+5;wx<o.x+o.w-8;wx+=14){ctx.beginPath();ctx.moveTo(wx,o.y+4);ctx.quadraticCurveTo(wx+3.5,o.y+1,wx+7,o.y+4);ctx.quadraticCurveTo(wx+10.5,o.y+7,wx+14,o.y+4);ctx.stroke();}
            } else {
                ctx.fillStyle='#0d2a4a';ctx.beginPath();ctx.roundRect(o.x,o.y,o.w,o.h,3);ctx.fill();
                ctx.fillStyle='#f57c00';ctx.font='bold 10px monospace';ctx.textAlign='center';ctx.fillText('404',o.x+o.w/2,o.y+o.h/2+4);
            }
        }
        for(var i=0;i<RN.shooters.length;i++){
            var s=RN.shooters[i];
            ctx.fillStyle='#1a3a5c';ctx.beginPath();ctx.roundRect(s.x,s.y,s.w,s.h,4);ctx.fill();
            ctx.fillStyle='rgba(220,38,38,0.9)';ctx.beginPath();ctx.roundRect(s.x+3,s.y+7,s.w-6,9,3);ctx.fill();
            ctx.fillStyle='#0d2a4a';ctx.fillRect(s.x-14,s.y+s.h-17,16,7);
            if(s.fl>0){ctx.fillStyle='rgba(255,180,0,'+(s.fl/12)+')';ctx.beginPath();ctx.arc(s.x-16,s.y+s.h-14,9,0,Math.PI*2);ctx.fill();}
            ctx.fillStyle='#0d2a4a';ctx.fillRect(s.x+3,GY-8,7,8);ctx.fillRect(s.x+s.w-10,GY-8,7,8);
        }
        for(var i=0;i<RN.rockets.length;i++){
            var r=RN.rockets[i];ctx.fillStyle='#dc2626';
            ctx.beginPath();ctx.moveTo(r.x,r.y+r.h/2);ctx.lineTo(r.x+10,r.y);ctx.lineTo(r.x+r.w,r.y);ctx.lineTo(r.x+r.w,r.y+r.h);ctx.lineTo(r.x+10,r.y+r.h);ctx.closePath();ctx.fill();
            ctx.fillStyle='rgba(255,255,255,0.75)';ctx.beginPath();ctx.arc(r.x+18,r.y+r.h/2,3,0,Math.PI*2);ctx.fill();
            var fl=6+Math.sin(RN.fr*0.8)*3;ctx.fillStyle='rgba(255,150,0,0.85)';ctx.beginPath();ctx.moveTo(r.x+r.w,r.y+2);ctx.lineTo(r.x+r.w+fl,r.y+r.h/2);ctx.lineTo(r.x+r.w,r.y+r.h-2);ctx.closePath();ctx.fill();
        }
        ctx.fillStyle='#f57c00';ctx.beginPath();ctx.roundRect(RN.px,RN.py,RN.pw,RN.ph,4);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.9)';ctx.beginPath();ctx.roundRect(RN.px+4,RN.py+6,RN.pw-8,10,3);ctx.fill();
        ctx.fillStyle='#0d2a4a';ctx.fillRect(RN.px+6,RN.py+8,4,4);ctx.fillRect(RN.px+16,RN.py+8,4,4);
        var ll=RN.run?(Math.sin(RN.fr*0.28)*4|0):0;
        ctx.fillStyle='#e65100';ctx.fillRect(RN.px+3,RN.py+RN.ph,7,5+ll);ctx.fillRect(RN.px+RN.pw-10,RN.py+RN.ph,7,5-ll);
        if(RN.jmps===2&&!RN.grnd){ctx.strokeStyle='rgba(255,200,60,0.75)';ctx.lineWidth=2;ctx.beginPath();ctx.arc(RN.px+RN.pw/2,RN.py+RN.ph/2,18+Math.sin(RN.fr*0.4)*3,0,Math.PI*2);ctx.stroke();}
    } else {
        ctx.fillStyle='#f57c00';ctx.beginPath();ctx.roundRect(RN.px,RN.py,RN.pw,RN.ph,4);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.9)';ctx.beginPath();ctx.roundRect(RN.px+4,RN.py+6,RN.pw-8,10,3);ctx.fill();
        ctx.fillStyle='#0d2a4a';ctx.fillRect(RN.px+6,RN.py+8,4,4);ctx.fillRect(RN.px+16,RN.py+8,4,4);
        ctx.fillStyle='#e65100';ctx.fillRect(RN.px+3,RN.py+RN.ph,7,5);ctx.fillRect(RN.px+RN.pw-10,RN.py+RN.ph,7,5);
        drawWelcome('404 Runner','Dodge obstacles & rockets');
    }
    drawParticles();
    if(RN.over)drawGameOver(RN.score,RN.newHi);
}

/* ═══════════════════════════════════════════════
   GAME 2 — JETPACK (Flappy Bird style)
   ═══════════════════════════════════════════════ */
var JP_GAP=105,JP_OBW=38;
var JP={run:false,over:false,score:0,fr:0,spd:2.4,py:H/2,vy:0,obs:[],next:90,pipes:0,newHi:false};
function jpReset(){
    JP.py=H/2;JP.vy=0;JP.obs=[];JP.score=0;JP.fr=0;JP.spd=2.4;JP.next=90;JP.pipes=0;
    JP.newHi=false;namePending=false;particles=[];
    if(nameOverlay)nameOverlay.style.display='none';
    JP.run=true;JP.over=false;
}
function jpBoost(){
    if(namePending)return;
    if(!JP.run&&!JP.over){jpReset();return;}
    if(JP.over){jpReset();return;}
    JP.vy=-4.8;
}
function jpDie(){if(JP.over)return;JP.over=true;JP.run=false;JP.newHi=checkNewHi('jetpack',JP.pipes);}
function jpUpdate(){
    if(!JP.run||JP.over)return;
    JP.fr++;JP.score++;JP.spd=2.4+Math.floor(JP.score/500)*0.25;
    JP.vy+=0.22;JP.py+=JP.vy;
    if(JP.py<8||JP.py>H-8){jpDie();return;}
    JP.next--;
    if(JP.next<=0){
        var jpGap=Math.max(62,JP_GAP-Math.floor(JP.pipes/10)*3);
        var gy=36+Math.floor(Math.random()*(H-jpGap-60));
        JP.obs.push({x:W,gy:gy,gap:jpGap,done:false});JP.next=130+Math.floor(Math.random()*50);
    }
    for(var i=JP.obs.length-1;i>=0;i--){
        JP.obs[i].x-=JP.spd;
        if(!JP.obs[i].done&&JP.obs[i].x+JP_OBW<80){JP.obs[i].done=true;JP.pipes++;}
        if(JP.obs[i].x+JP_OBW<0){JP.obs.splice(i,1);continue;}
        if(80+9>JP.obs[i].x&&80-9<JP.obs[i].x+JP_OBW){
            if(JP.py-9<JP.obs[i].gy||JP.py+9>JP.obs[i].gy+JP.obs[i].gap){jpDie();return;}
        }
    }
}
function jpDraw(){
    ctx.clearRect(0,0,W,H);
    var g=ctx.createLinearGradient(0,0,0,H);g.addColorStop(0,'#1e3a5f');g.addColorStop(1,'#1d4ed8');
    ctx.fillStyle=g;ctx.fillRect(0,0,W,H);
    ctx.fillStyle='rgba(255,255,255,0.35)';
    for(var i=0;i<24;i++){ctx.beginPath();ctx.arc((i*37+JP.fr*0.15)%W,28+i*10,1,0,Math.PI*2);ctx.fill();}
    drawHiPanel('jetpack');drawScore(JP.pipes);
    for(var i=0;i<JP.obs.length;i++){
        var o=JP.obs[i];
        ctx.fillStyle='#15803d';ctx.beginPath();ctx.roundRect(o.x,0,JP_OBW,o.gy,4);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.12)';ctx.fillRect(o.x+4,0,8,o.gy);
        ctx.fillStyle='#15803d';ctx.beginPath();ctx.roundRect(o.x,o.gy+o.gap,JP_OBW,H-o.gy-o.gap,4);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.12)';ctx.fillRect(o.x+4,o.gy+o.gap,8,H-o.gy-o.gap);
    }
    var py=JP.py;
    if(JP.run&&JP.vy<0){
        var fl=8+Math.random()*5;ctx.fillStyle='rgba(255,150,0,0.85)';
        ctx.beginPath();ctx.moveTo(76,py+8);ctx.lineTo(72,py+fl);ctx.lineTo(68,py+8);ctx.closePath();ctx.fill();
    }
    ctx.fillStyle='#f57c00';ctx.beginPath();ctx.roundRect(70,py-10,20,22,4);ctx.fill();
    ctx.fillStyle='#1a3a5c';ctx.beginPath();ctx.roundRect(66,py-8,8,18,3);ctx.fill();
    ctx.fillStyle='rgba(245,124,0,0.6)';ctx.beginPath();ctx.arc(70,py+8,4,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.9)';ctx.beginPath();ctx.roundRect(73,py-7,14,9,3);ctx.fill();
    ctx.fillStyle='#0d2a4a';ctx.fillRect(75,py-5,3,3);ctx.fillRect(81,py-5,3,3);
    if(!JP.run&&!JP.over)drawWelcome('Jetpack Pilot','Fly through the gaps');
    drawParticles();
    if(JP.over)drawGameOver(JP.pipes,JP.newHi);
}

/* ═══════════════════════════════════════════════
   GAME 3 — RACER (top-down 3-lane)
   ═══════════════════════════════════════════════ */
var RD_X=Math.floor((W-360)/2),RD_W=360,LN_W=120;
var LNS=[RD_X+60,RD_X+180,RD_X+300];
var RC_COLS=['#dc2626','#2563eb','#16a34a','#d97706','#7c3aed','#db2777'];
var RC={run:false,over:false,score:0,fr:0,spd:3.5,lane:1,tx:0,cx:0,cars:[],next:60,dash:0,newHi:false};
RC.tx=LNS[1];RC.cx=LNS[1];
function rcReset(){
    RC.lane=1;RC.tx=LNS[1];RC.cx=LNS[1];RC.cars=[];RC.score=0;RC.fr=0;RC.spd=3.5;RC.next=60;RC.dash=0;
    RC.newHi=false;namePending=false;particles=[];
    if(nameOverlay)nameOverlay.style.display='none';RC.run=true;RC.over=false;
}
function rcMove(dir){
    if(namePending)return;
    if(!RC.run&&!RC.over){rcReset();return;}
    if(RC.over){rcReset();return;}
    if(dir==='l'&&RC.lane>0){RC.lane--;RC.tx=LNS[RC.lane];}
    if(dir==='r'&&RC.lane<2){RC.lane++;RC.tx=LNS[RC.lane];}
}
function rcDie(){if(RC.over)return;RC.over=true;RC.run=false;RC.newHi=checkNewHi('racer',RC.score);}
function rcUpdate(){
    if(!RC.run||RC.over)return;
    RC.fr++;RC.score++;RC.spd=3.5+Math.floor(RC.score/600)*0.4;
    RC.dash=(RC.dash+RC.spd*2)%40;RC.cx+=(RC.tx-RC.cx)*0.18;
    RC.next--;
    if(RC.next<=0){
        var ln=Math.floor(Math.random()*3);
        RC.cars.push({x:LNS[ln],y:-50,col:RC_COLS[Math.floor(Math.random()*RC_COLS.length)]});
        RC.next=55+Math.floor(Math.random()*40);
    }
    for(var i=RC.cars.length-1;i>=0;i--){
        RC.cars[i].y+=RC.spd*2;
        if(RC.cars[i].y>H+60){RC.cars.splice(i,1);continue;}
        if(Math.abs(RC.cx-RC.cars[i].x)<22&&Math.abs(H-70-RC.cars[i].y)<36){rcDie();return;}
    }
}
function rcDrawCar(cx,cy,col,pl){
    var x=cx-15,y=cy-25;
    ctx.fillStyle=col;ctx.beginPath();ctx.roundRect(x,y+8,30,34,6);ctx.fill();
    ctx.fillStyle=pl?'#c2410c':'rgba(0,0,0,0.35)';ctx.beginPath();ctx.roundRect(x+4,y+14,22,22,3);ctx.fill();
    ctx.fillStyle='rgba(200,240,255,0.75)';ctx.beginPath();ctx.roundRect(x+5,y+15,20,8,2);ctx.fill();ctx.beginPath();ctx.roundRect(x+5,y+28,20,7,2);ctx.fill();
    ctx.fillStyle='#111';ctx.fillRect(x-2,y+10,5,9);ctx.fillRect(x+27,y+10,5,9);ctx.fillRect(x-2,y+31,5,9);ctx.fillRect(x+27,y+31,5,9);
    if(pl){ctx.fillStyle='#fbbf24';ctx.fillRect(x+2,y+8,6,3);ctx.fillRect(x+22,y+8,6,3);}
    else{ctx.fillStyle='#ef4444';ctx.fillRect(x+2,y+39,6,3);ctx.fillRect(x+22,y+39,6,3);}
}
function rcDraw(){
    ctx.clearRect(0,0,W,H);
    ctx.fillStyle='#22c55e';ctx.fillRect(0,0,W,H);
    ctx.fillStyle='#374151';ctx.fillRect(RD_X,0,RD_W,H);
    ctx.fillStyle='#fff';ctx.fillRect(RD_X,0,4,H);ctx.fillRect(RD_X+RD_W-4,0,4,H);
    ctx.save();ctx.strokeStyle='rgba(255,255,255,0.5)';ctx.lineWidth=2;ctx.setLineDash([20,20]);ctx.lineDashOffset=-RC.dash;
    ctx.beginPath();ctx.moveTo(RD_X+LN_W,0);ctx.lineTo(RD_X+LN_W,H);ctx.stroke();
    ctx.beginPath();ctx.moveTo(RD_X+LN_W*2,0);ctx.lineTo(RD_X+LN_W*2,H);ctx.stroke();
    ctx.restore();
    drawHiPanel('racer');drawScore(RC.score);
    for(var i=0;i<RC.cars.length;i++)rcDrawCar(RC.cars[i].x,RC.cars[i].y,RC.cars[i].col,false);
    rcDrawCar(RC.cx,H-70,'#f57c00',true);
    if(!RC.run&&!RC.over)drawWelcome('Street Racer','← left half  |  right half → steer');
    drawParticles();
    if(RC.over)drawGameOver(RC.score,RC.newHi);
}

/* ═══════════════════════════════════════════════
   GAME 4 — MANIC MINER (10 levels)
   ═══════════════════════════════════════════════ */
var MM_PW=16,MM_PH=20,MM_SB=24,MM_PH8=8;
// Level data: plat=[x,y,w], en={x,py,pa,pb,sp,d}, keys=[{x,y}], sx,sy,ex,ey
// py = bottom-y of character/enemy when standing on that platform
// Platform at y=268 → player py=268, player top = 248
var MM_LEVELS=[
    // 1 - Introduction
    {plat:[[0,268,620],[60,220,110],[250,190,100],[420,220,110],[160,152,90],[430,152,80]],
     en:[{x:280,py:268,pa:160,pb:440,sp:1.5,d:1},{x:260,py:190,pa:250,pb:340,sp:1.6,d:1}],
     keys:[{x:95,y:206},{x:285,y:176},{x:455,y:206},{x:195,y:138},{x:465,y:138}],
     sx:30,sy:268,ex:570,ey:268},
    // 2 - Two tiers
    {plat:[[0,268,120],[170,268,120],[340,268,120],[500,268,120],[80,218,100],[260,218,100],[440,218,100],[150,165,100],[370,165,100]],
     en:[{x:90,py:218,pa:80,pb:180,sp:1.8,d:1},{x:270,py:218,pa:260,pb:360,sp:1.8,d:-1},{x:385,py:165,pa:370,pb:470,sp:2,d:1}],
     keys:[{x:110,y:204},{x:300,y:204},{x:470,y:204},{x:190,y:151},{x:410,y:151}],
     sx:10,sy:268,ex:565,ey:268},
    // 3 - Stepping stones
    {plat:[[0,268,80],[110,268,60],[220,268,60],[330,268,60],[450,268,80],[560,268,60],[50,225,60],[160,205,60],[270,185,60],[380,205,60],[490,225,60],[560,185,60]],
     en:[{x:115,py:268,pa:110,pb:170,sp:2,d:1},{x:335,py:268,pa:330,pb:390,sp:2,d:-1},{x:395,py:205,pa:380,pb:440,sp:2.2,d:1}],
     keys:[{x:70,y:243},{x:175,y:181},{x:290,y:161},{x:405,y:181},{x:575,y:161}],
     sx:10,sy:268,ex:570,ey:185},
    // 4 - Towers
    {plat:[[0,268,620],[60,238,40],[140,208,40],[220,178,40],[310,148,40],[400,178,40],[480,208,40],[560,238,40],[80,118,70],[300,100,70],[520,118,70]],
     en:[{x:220,py:268,pa:80,pb:540,sp:2,d:1},{x:145,py:208,pa:140,pb:220,sp:2.2,d:1},{x:405,py:208,pa:400,pb:480,sp:2.2,d:-1},{x:310,py:100,pa:300,pb:370,sp:3,d:1}],
     keys:[{x:75,y:214},{x:155,y:184},{x:235,y:154},{x:325,y:124},{x:415,y:154},{x:495,y:184},{x:575,y:214},{x:325,y:76}],
     sx:10,sy:268,ex:545,ey:268},
    // 5 - Zigzag
    {plat:[[0,268,100],[150,248,80],[290,228,80],[430,208,80],[530,188,90],[420,158,80],[290,170,80],[150,158,80],[50,138,80],[170,118,80],[340,108,80],[510,118,80]],
     en:[{x:155,py:248,pa:150,pb:230,sp:2,d:1},{x:295,py:228,pa:290,pb:370,sp:2.3,d:1},{x:435,py:208,pa:430,pb:510,sp:2,d:-1},{x:295,py:170,pa:290,pb:370,sp:2.5,d:1},{x:340,py:108,pa:340,pb:420,sp:3,d:1}],
     keys:[{x:175,y:224},{x:315,y:204},{x:455,y:184},{x:440,y:134},{x:180,y:94},{x:355,y:84},{x:530,y:94}],
     sx:10,sy:268,ex:510,ey:118},
    // 6 - Gauntlet
    {plat:[[0,268,620],[0,218,180],[200,218,60],[420,218,200],[100,168,100],[280,158,80],[440,168,100],[0,118,100],[200,113,80],[420,118,100],[540,113,80],[180,68,260]],
     en:[{x:50,py:218,pa:0,pb:180,sp:2.5,d:1},{x:430,py:218,pa:420,pb:620,sp:2.5,d:-1},{x:115,py:168,pa:100,pb:200,sp:2.5,d:1},{x:455,py:168,pa:440,pb:540,sp:2.5,d:-1},{x:10,py:118,pa:0,pb:100,sp:3,d:1},{x:435,py:118,pa:420,pb:620,sp:3,d:-1},{x:250,py:68,pa:180,pb:440,sp:3.5,d:1}],
     keys:[{x:80,y:194},{x:450,y:194},{x:135,y:144},{x:475,y:144},{x:50,y:94},{x:475,y:94},{x:305,y:44}],
     sx:10,sy:268,ex:570,ey:268},
    // 7 - Island hopping
    {plat:[[0,268,60],[100,268,80],[240,268,60],[360,268,80],[480,268,60],[570,268,50],[50,220,70],[180,200,70],[320,200,70],[460,220,70],[555,200,65],[80,155,60],[240,140,70],[400,155,60],[520,133,60],[160,100,70],[360,100,70],[500,103,60]],
     en:[{x:110,py:268,pa:100,pb:180,sp:2.2,d:1},{x:370,py:268,pa:360,pb:440,sp:2.2,d:-1},{x:195,py:200,pa:180,pb:250,sp:2.5,d:1},{x:475,py:220,pa:460,pb:530,sp:2.5,d:-1},{x:255,py:140,pa:240,pb:310,sp:3,d:1},{x:370,py:100,pa:360,pb:430,sp:3,d:-1}],
     keys:[{x:75,y:244},{x:210,y:176},{x:345,y:176},{x:580,y:244},{x:95,y:131},{x:535,y:109},{x:185,y:76},{x:375,y:76}],
     sx:10,sy:268,ex:520,ey:103},
    // 8 - Speed run
    {plat:[[0,268,620],[80,228,80],[220,208,80],[360,228,80],[500,208,80],[140,173,60],[300,153,60],[440,173,60],[570,153,60],[80,118,60],[220,98,60],[380,118,60],[500,98,60]],
     en:[{x:90,py:228,pa:80,pb:160,sp:3,d:1},{x:230,py:208,pa:220,pb:300,sp:3.2,d:-1},{x:370,py:228,pa:360,pb:440,sp:3,d:1},{x:510,py:208,pa:500,pb:580,sp:3.2,d:-1},{x:150,py:173,pa:140,pb:200,sp:3.5,d:1},{x:310,py:153,pa:300,pb:360,sp:3.5,d:-1},{x:450,py:173,pa:440,pb:500,sp:3.5,d:1}],
     keys:[{x:100,y:204},{x:250,y:184},{x:390,y:204},{x:530,y:184},{x:160,y:149},{x:320,y:129},{x:460,y:149},{x:530,y:74}],
     sx:10,sy:268,ex:500,ey:98},
    // 9 - The Maze
    {plat:[[0,268,620],[0,228,80],[180,228,80],[360,228,80],[540,228,80],[80,193,80],[260,193,80],[440,193,80],[0,156,60],[140,156,60],[280,156,80],[440,156,60],[560,156,60],[60,118,70],[200,118,70],[360,118,70],[500,118,70],[0,80,60],[160,80,70],[310,80,70],[460,80,70],[560,80,60]],
     en:[{x:0,py:228,pa:0,pb:80,sp:2.5,d:1},{x:190,py:228,pa:180,pb:260,sp:2.5,d:-1},{x:370,py:228,pa:360,pb:440,sp:2.5,d:1},{x:550,py:228,pa:540,pb:620,sp:2.5,d:-1},{x:90,py:193,pa:80,pb:160,sp:3,d:1},{x:270,py:193,pa:260,pb:340,sp:3,d:-1},{x:450,py:193,pa:440,pb:520,sp:3,d:1},{x:70,py:118,pa:60,pb:130,sp:3.5,d:1},{x:210,py:118,pa:200,pb:270,sp:3.5,d:-1},{x:370,py:118,pa:360,pb:430,sp:3.5,d:1},{x:510,py:118,pa:500,pb:570,sp:3.5,d:-1}],
     keys:[{x:30,y:204},{x:210,y:204},{x:390,y:204},{x:570,y:204},{x:100,y:169},{x:280,y:169},{x:460,y:169},{x:80,y:94},{x:225,y:94},{x:375,y:94},{x:510,y:56}],
     sx:10,sy:268,ex:540,ey:268},
    // 10 - Final Challenge
    {plat:[[0,268,620],[0,240,50],[90,240,50],[180,240,50],[270,240,50],[360,240,50],[450,240,50],[540,240,80],[45,208,50],[135,208,50],[225,208,50],[315,208,50],[405,208,50],[495,208,50],[0,176,50],[90,176,50],[200,176,50],[310,176,50],[420,176,50],[530,176,90],[0,143,60],[140,143,60],[280,143,60],[420,143,60],[540,143,80],[80,108,50],[200,108,50],[330,108,50],[460,108,50],[160,73,60],[310,73,70],[470,73,60]],
     en:[{x:0,py:240,pa:0,pb:50,sp:3,d:1},{x:90,py:240,pa:90,pb:140,sp:3.2,d:1},{x:180,py:240,pa:180,pb:230,sp:3,d:-1},{x:270,py:240,pa:270,pb:320,sp:3.2,d:1},{x:360,py:240,pa:360,pb:410,sp:3,d:-1},{x:450,py:240,pa:450,pb:500,sp:3.2,d:1},{x:55,py:208,pa:45,pb:95,sp:3.5,d:1},{x:145,py:208,pa:135,pb:185,sp:3.5,d:-1},{x:235,py:208,pa:225,pb:275,sp:3.5,d:1},{x:325,py:208,pa:315,pb:365,sp:3.5,d:-1},{x:415,py:208,pa:405,pb:455,sp:3.5,d:1},{x:510,py:208,pa:495,pb:545,sp:3.5,d:-1},{x:10,py:176,pa:0,pb:50,sp:4,d:1},{x:100,py:176,pa:90,pb:140,sp:4,d:-1},{x:210,py:176,pa:200,pb:250,sp:4,d:1},{x:320,py:176,pa:310,pb:360,sp:4,d:-1},{x:430,py:176,pa:420,pb:470,sp:4,d:1},{x:540,py:176,pa:530,pb:620,sp:4,d:-1}],
     keys:[{x:15,y:216},{x:105,y:216},{x:195,y:216},{x:285,y:216},{x:375,y:216},{x:465,y:216},{x:555,y:216},{x:60,y:184},{x:150,y:184},{x:320,y:184},{x:510,y:184},{x:100,y:84},{x:220,y:84},{x:345,y:84},{x:490,y:84}],
     sx:10,sy:268,ex:490,ey:108}
];
var MM={run:false,over:false,won:false,score:0,fr:0,lives:3,level:0,
    px:30,py:268,pvx:0,pvy:0,pgrnd:false,
    plat:[],enemies:[],keys:[],exit:{x:0,y:0},exitOpen:false,
    dyingTimer:0,newHi:false};
function mmLoad(lvl){
    var d=MM_LEVELS[lvl];
    MM.plat=d.plat.map(function(p){return{x:p[0],y:p[1],w:p[2]};});
    MM.enemies=d.en.map(function(e){return{x:e.x,py:e.py,pa:e.pa,pb:e.pb,sp:e.sp,d:e.d};});
    MM.keys=d.keys.map(function(k){return{x:k.x,y:k.y,got:false};});
    MM.exit={x:d.ex,y:d.ey};MM.exitOpen=false;
    MM.px=d.sx;MM.py=d.sy;MM.pvx=0;MM.pvy=0;MM.pgrnd=false;MM.dyingTimer=0;
}
function mmReset(){
    MM.run=false;MM.over=false;MM.won=false;MM.score=0;MM.fr=0;MM.lives=3;MM.level=0;
    MM.newHi=false;namePending=false;particles=[];
    if(nameOverlay)nameOverlay.style.display='none';
    mmLoad(0);MM.run=true;
}
function mmDie(){
    if(MM.dyingTimer>0||MM.over)return;
    MM.dyingTimer=50;MM.pvx=0;MM.pvy=0;
}
var mmKeys={left:false,right:false,jump:false};
var mmJumpLock=false;
function mmUpdate(){
    if(!MM.run||MM.over)return;
    if(MM.dyingTimer>0){
        MM.dyingTimer--;
        if(MM.dyingTimer===0){
            MM.lives--;
            if(MM.lives<=0){MM.over=true;MM.run=false;MM.newHi=checkNewHi('miner',MM.score);}
            else{var d=MM_LEVELS[MM.level];MM.px=d.sx;MM.py=d.sy;MM.pvx=0;MM.pvy=0;}
        }
        return;
    }
    MM.fr++;
    if(mmKeys.left){MM.pvx=-2;}else if(mmKeys.right){MM.pvx=2;}else MM.pvx*=0.5;
    if(mmKeys.jump&&MM.pgrnd&&!mmJumpLock){MM.pvy=-9;MM.pgrnd=false;mmJumpLock=true;}
    if(!mmKeys.jump)mmJumpLock=false;
    MM.pvy+=0.5;
    MM.px+=MM.pvx;MM.py+=MM.pvy;
    if(MM.px<0)MM.px=0;if(MM.px+MM_PW>W)MM.px=W-MM_PW;
    // Platform landing (top only)
    MM.pgrnd=false;
    for(var i=0;i<MM.plat.length;i++){
        var p=MM.plat[i];
        if(MM.px+MM_PW>p.x+3&&MM.px<p.x+p.w-3){
            if(MM.pvy>=0&&MM.py>=p.y&&MM.py-MM.pvy<p.y+1){
                MM.py=p.y;MM.pvy=0;MM.pgrnd=true;break;
            }
        }
    }
    if(MM.py>H+30){mmDie();return;}
    // Enemy collisions
    for(var i=0;i<MM.enemies.length;i++){
        var e=MM.enemies[i];
        e.x+=e.sp*e.d;
        if(e.x<=e.pa)e.d=1;if(e.x+16>=e.pb)e.d=-1;
        if(MM.px+MM_PW-3>e.x+2&&MM.px+3<e.x+13&&MM.py>e.py-17&&MM.py-MM_PH<e.py+1){mmDie();return;}
    }
    // Key collection
    var allGot=true;
    for(var i=0;i<MM.keys.length;i++){
        if(!MM.keys[i].got){
            allGot=false;
            if(Math.abs(MM.px+MM_PW/2-MM.keys[i].x)<14&&Math.abs(MM.py-MM.keys[i].y)<14){
                MM.keys[i].got=true;MM.score+=10;
            }
        }
    }
    if(allGot&&!MM.exitOpen)MM.exitOpen=true;
    // Exit
    if(MM.exitOpen&&Math.abs(MM.px+MM_PW/2-MM.exit.x)<20&&Math.abs(MM.py-MM.exit.y)<22){
        MM.score+=100;MM.level++;
        if(MM.level>=MM_LEVELS.length){
            MM.won=true;MM.over=true;MM.run=false;MM.newHi=checkNewHi('miner',MM.score);
            burstFireworks();burstFireworks();
        } else mmLoad(MM.level);
    }
}
function mmDraw(){
    ctx.clearRect(0,0,W,H);
    ctx.fillStyle='#0d1b2e';ctx.fillRect(0,0,W,H);
    // Platforms
    for(var i=0;i<MM.plat.length;i++){
        var p=MM.plat[i];
        ctx.fillStyle=i===0?'#1a3a5f':'#234e7a';
        ctx.beginPath();ctx.roundRect(p.x,p.y,p.w,MM_PH8,2);ctx.fill();
        ctx.fillStyle='rgba(100,200,255,0.25)';ctx.fillRect(p.x+2,p.y,p.w-4,2);
    }
    // Keys
    for(var i=0;i<MM.keys.length;i++){
        if(!MM.keys[i].got){
            var kx=MM.keys[i].x,ky=MM.keys[i].y;
            ctx.save();ctx.translate(kx,ky);ctx.rotate(Math.sin(MM.fr*0.08+i)*0.15);
            ctx.fillStyle='#f59e0b';ctx.beginPath();ctx.moveTo(0,-8);ctx.lineTo(6,0);ctx.lineTo(0,8);ctx.lineTo(-6,0);ctx.closePath();ctx.fill();
            ctx.fillStyle='rgba(255,230,100,0.55)';ctx.beginPath();ctx.arc(0,0,5,0,Math.PI*2);ctx.fill();
            ctx.restore();
        }
    }
    // Exit door
    if(MM.exitOpen){
        var fl=0.7+Math.sin(MM.fr*0.2)*0.3;
        ctx.fillStyle='rgba(34,197,94,'+fl+')';
        ctx.beginPath();ctx.roundRect(MM.exit.x-12,MM.exit.y-22,24,24,3);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.85)';ctx.font='bold 8px monospace';ctx.textAlign='center';
        ctx.fillText('EXIT',MM.exit.x,MM.exit.y-10);
    }
    // Enemies
    for(var i=0;i<MM.enemies.length;i++){
        var e=MM.enemies[i];var ey=e.py-16;
        ctx.fillStyle='#dc2626';ctx.beginPath();ctx.roundRect(e.x,ey,16,16,3);ctx.fill();
        ctx.fillStyle='rgba(255,255,255,0.9)';ctx.fillRect(e.x+2,ey+3,5,5);ctx.fillRect(e.x+9,ey+3,5,5);
        ctx.fillStyle='#111';ctx.fillRect(e.x+3,ey+4,3,3);ctx.fillRect(e.x+10,ey+4,3,3);
        var el=Math.sin(MM.fr*0.3)*2|0;
        ctx.fillStyle='#991b1b';ctx.fillRect(e.x+2,e.py,5,4+el);ctx.fillRect(e.x+9,e.py,5,4-el);
    }
    // Player (flicker when dying)
    if(MM.dyingTimer===0||Math.floor(MM.dyingTimer/5)%2===0){
        var ppx=MM.px,ppy=MM.py-MM_PH;
        ctx.fillStyle=MM.dyingTimer>0?'#fff':'#f57c00';
        ctx.beginPath();ctx.roundRect(ppx,ppy,MM_PW,MM_PH,3);ctx.fill();
        if(MM.dyingTimer===0){
            ctx.fillStyle='rgba(255,255,255,0.9)';ctx.beginPath();ctx.roundRect(ppx+3,ppy+4,MM_PW-6,8,2);ctx.fill();
            ctx.fillStyle='#0d2a4a';ctx.fillRect(ppx+4,ppy+5,3,3);ctx.fillRect(ppx+9,ppy+5,3,3);
            var ll=Math.abs(MM.pvx)>0.2?(Math.sin(MM.fr*0.4)*3|0):0;
            ctx.fillStyle='#e65100';ctx.fillRect(ppx+2,MM.py,5,4+ll);ctx.fillRect(ppx+9,MM.py,5,4-ll);
        }
    }
    // Status bar
    ctx.fillStyle='rgba(9,20,40,0.92)';ctx.fillRect(0,0,W,MM_SB);
    ctx.font='bold 11px monospace';ctx.fillStyle='#f57c00';ctx.textAlign='left';
    ctx.fillText('Lvl '+(MM.level+1)+'/'+MM_LEVELS.length,8,16);
    ctx.fillStyle='#60a5fa';ctx.textAlign='center';
    ctx.fillText('Score: '+MM.score,W/2,16);
    ctx.textAlign='right';
    for(var i=0;i<MM.lives;i++){ctx.fillStyle='#f57c00';ctx.beginPath();ctx.roundRect(W-14-i*18,4,10,14,2);ctx.fill();}
    var kl=MM.keys.filter(function(k){return!k.got;}).length;
    if(kl>0){ctx.fillStyle='#f59e0b';ctx.textAlign='center';ctx.font='10px monospace';ctx.fillText('\u25C6\xD7'+kl,W/2+50,16);}
    if(!MM.run&&!MM.over){drawWelcome('Manic Miner','10 levels  \u2014  collect all keys \u2192 exit');}
    drawParticles();
    if(MM.over){
        if(MM.won){
            ctx.fillStyle='rgba(204,233,251,0.92)';ctx.beginPath();ctx.roundRect(W/2-140,H/2-40,280,80,8);ctx.fill();
            ctx.textAlign='center';ctx.fillStyle='#f57c00';ctx.font='bold 15px monospace';
            ctx.fillText('\uD83C\uDFC6 YOU BEAT ALL 10 LEVELS!',W/2,H/2-12);
            ctx.fillStyle='#0d2a4a';ctx.font='12px monospace';ctx.fillText('Score: '+MM.score,W/2,H/2+10);
            ctx.font='10px monospace';ctx.fillStyle='#3a6080';ctx.fillText('SPACE or TAP to play again',W/2,H/2+30);
        } else drawGameOver(MM.score,MM.newHi);
    }
}
// Miner mobile controls
['ml','mj','mr'].forEach(function(id){
    var el=document.getElementById('cs404-'+id);
    if(!el)return;
    function dn(e){
        e.preventDefault();
        if(id==='ml')mmKeys.left=true;
        else if(id==='mr')mmKeys.right=true;
        else mmKeys.jump=true;
    }
    function up(){
        if(id==='ml')mmKeys.left=false;
        else if(id==='mr')mmKeys.right=false;
        else mmKeys.jump=false;
    }
    el.addEventListener('touchstart',dn,{passive:false});
    el.addEventListener('touchend',up);
    el.addEventListener('mousedown',dn);
    el.addEventListener('mouseup',up);
});

/* ═══════════════════════════════════════════════
   GAME 5 — ASTEROIDS
   ═══════════════════════════════════════════════ */
var AS_STARS=[];
(function(){for(var i=0;i<55;i++)AS_STARS.push({x:Math.random()*W,y:Math.random()*H,r:Math.random()<0.2?1.2:0.6});}());
var AS={run:false,over:false,score:0,fr:0,lives:3,wave:1,
    ship:{x:W/2,y:H/2,angle:-Math.PI/2,vx:0,vy:0,dead:false,deathTimer:0,invTimer:0},
    bullets:[],asteroids:[],newHi:false};
var AS_SHOOT_CD=0;
function asNewAsteroid(x,y,size){
    var a=Math.random()*Math.PI*2,sp=(3-size)*0.7+0.6+Math.random()*0.8;
    var verts=7+Math.floor(Math.random()*4),pts=[],br=size===1?34:size===2?18:9;
    for(var i=0;i<verts;i++){var ang=i/verts*Math.PI*2,r=br*(0.75+Math.random()*0.45);pts.push({x:Math.cos(ang)*r,y:Math.sin(ang)*r});}
    return{x:x,y:y,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp,size:size,pts:pts,angle:Math.random()*Math.PI*2,spin:(Math.random()-0.5)*0.04};
}
function asSpawnWave(){
    AS.asteroids=[];
    var n=Math.min(3+AS.wave,9);
    for(var i=0;i<n;i++){
        var x,y,tries=0;
        do{x=Math.random()*W;y=Math.random()*H;tries++;}
        while(tries<20&&Math.abs(x-AS.ship.x)<90&&Math.abs(y-AS.ship.y)<90);
        AS.asteroids.push(asNewAsteroid(x,y,1));
    }
}
function asReset(){
    AS.score=0;AS.fr=0;AS.lives=3;AS.wave=1;AS.bullets=[];
    AS.ship={x:W/2,y:H/2,angle:-Math.PI/2,vx:0,vy:0,dead:false,deathTimer:0,invTimer:60};
    AS.newHi=false;namePending=false;particles=[];AS_SHOOT_CD=0;
    if(nameOverlay)nameOverlay.style.display='none';
    asSpawnWave();AS.run=true;AS.over=false;
}
var asKeys={left:false,right:false,up:false,shoot:false};
var asShootLock=false;
function asUpdate(){
    if(!AS.run||AS.over)return;
    AS.fr++;AS_SHOOT_CD=Math.max(0,AS_SHOOT_CD-1);
    var sh=AS.ship;
    if(sh.dead){
        sh.deathTimer--;
        if(sh.deathTimer<=0){
            AS.lives--;
            if(AS.lives<=0){AS.over=true;AS.run=false;AS.newHi=checkNewHi('asteroids',AS.score);return;}
            sh.x=W/2;sh.y=H/2;sh.vx=0;sh.vy=0;sh.angle=-Math.PI/2;sh.dead=false;sh.invTimer=90;
        }
    } else {
        if(asKeys.left)sh.angle-=0.07;
        if(asKeys.right)sh.angle+=0.07;
        if(asKeys.up){sh.vx+=Math.cos(sh.angle)*0.28;sh.vy+=Math.sin(sh.angle)*0.28;}
        sh.vx*=0.985;sh.vy*=0.985;
        var spd=Math.sqrt(sh.vx*sh.vx+sh.vy*sh.vy);if(spd>5.5){sh.vx=sh.vx/spd*5.5;sh.vy=sh.vy/spd*5.5;}
        sh.x=(sh.x+sh.vx+W)%W;sh.y=(sh.y+sh.vy+H)%H;
        if(sh.invTimer>0)sh.invTimer--;
        if(asKeys.shoot&&!asShootLock&&AS_SHOOT_CD===0){
            AS.bullets.push({x:sh.x+Math.cos(sh.angle)*15,y:sh.y+Math.sin(sh.angle)*15,vx:Math.cos(sh.angle)*7.5+sh.vx,vy:Math.sin(sh.angle)*7.5+sh.vy,life:52});
            AS_SHOOT_CD=10;asShootLock=true;
        }
        if(!asKeys.shoot)asShootLock=false;
    }
    for(var i=AS.bullets.length-1;i>=0;i--){
        var b=AS.bullets[i];b.x=(b.x+b.vx+W)%W;b.y=(b.y+b.vy+H)%H;b.life--;
        if(b.life<=0)AS.bullets.splice(i,1);
    }
    for(var i=0;i<AS.asteroids.length;i++){
        var a=AS.asteroids[i];a.x=(a.x+a.vx+W)%W;a.y=(a.y+a.vy+H)%H;a.angle+=a.spin;
    }
    // bullet-asteroid collisions
    for(var bi=AS.bullets.length-1;bi>=0;bi--){
        var b=AS.bullets[bi],hit=false;
        for(var ai=AS.asteroids.length-1;ai>=0;ai--){
            var a=AS.asteroids[ai],r=a.size===1?34:a.size===2?18:9;
            var dx=b.x-a.x,dy=b.y-a.y;
            if(dx*dx+dy*dy<r*r){
                AS.score+=a.size===1?10:a.size===2?20:50;
                if(a.size<3){AS.asteroids.push(asNewAsteroid(a.x,a.y,a.size+1));AS.asteroids.push(asNewAsteroid(a.x,a.y,a.size+1));}
                for(var p=0;p<8;p++){var pa=Math.random()*Math.PI*2,ps=1+Math.random()*2;particles.push({x:a.x,y:a.y,vx:Math.cos(pa)*ps,vy:Math.sin(pa)*ps,life:1,dec:0.04+Math.random()*0.03,r:2+Math.random()*2,col:'#4a8ab5'});}
                AS.asteroids.splice(ai,1);AS.bullets.splice(bi,1);hit=true;break;
            }
        }
        if(hit)continue;
    }
    // ship-asteroid collision
    if(!sh.dead&&sh.invTimer===0){
        for(var ai=0;ai<AS.asteroids.length;ai++){
            var a=AS.asteroids[ai],r=(a.size===1?34:a.size===2?18:9)+9;
            var dx=sh.x-a.x,dy=sh.y-a.y;
            if(dx*dx+dy*dy<r*r){sh.dead=true;sh.deathTimer=60;burstFireworks();break;}
        }
    }
    if(AS.asteroids.length===0){AS.wave++;asSpawnWave();}
}
function asDraw(){
    ctx.clearRect(0,0,W,H);
    ctx.fillStyle='#080d1a';ctx.fillRect(0,0,W,H);
    ctx.fillStyle='rgba(255,255,255,0.7)';
    for(var i=0;i<AS_STARS.length;i++){ctx.beginPath();ctx.arc(AS_STARS[i].x,AS_STARS[i].y,AS_STARS[i].r,0,Math.PI*2);ctx.fill();}
    drawHiPanel('asteroids');drawScore(AS.score);
    for(var i=0;i<AS.asteroids.length;i++){
        var a=AS.asteroids[i];
        ctx.save();ctx.translate(a.x,a.y);ctx.rotate(a.angle);
        ctx.strokeStyle='#4a8ab5';ctx.lineWidth=1.8;ctx.fillStyle='rgba(13,42,74,0.75)';
        ctx.beginPath();ctx.moveTo(a.pts[0].x,a.pts[0].y);
        for(var j=1;j<a.pts.length;j++)ctx.lineTo(a.pts[j].x,a.pts[j].y);
        ctx.closePath();ctx.fill();ctx.stroke();
        ctx.restore();
    }
    ctx.fillStyle='#f59e0b';
    for(var i=0;i<AS.bullets.length;i++){ctx.beginPath();ctx.arc(AS.bullets[i].x,AS.bullets[i].y,2.5,0,Math.PI*2);ctx.fill();}
    var sh=AS.ship;
    if(!sh.dead&&(sh.invTimer===0||Math.floor(sh.invTimer/6)%2===0)){
        ctx.save();ctx.translate(sh.x,sh.y);ctx.rotate(sh.angle);
        if(asKeys.up&&AS.fr%4<2){
            ctx.fillStyle='rgba(255,140,0,0.85)';
            ctx.beginPath();ctx.moveTo(-10,5);ctx.lineTo(-19,0);ctx.lineTo(-10,-5);ctx.closePath();ctx.fill();
        }
        ctx.strokeStyle='#f57c00';ctx.lineWidth=2;ctx.fillStyle='rgba(245,124,0,0.18)';
        ctx.beginPath();ctx.moveTo(14,0);ctx.lineTo(-10,9);ctx.lineTo(-6,0);ctx.lineTo(-10,-9);ctx.closePath();
        ctx.fill();ctx.stroke();
        ctx.restore();
    }
    // status bar
    ctx.fillStyle='rgba(8,13,26,0.88)';ctx.fillRect(0,0,W,20);
    ctx.font='bold 11px monospace';ctx.fillStyle='#f57c00';ctx.textAlign='left';
    ctx.fillText('Wave '+AS.wave,8,14);
    ctx.textAlign='right';
    for(var i=0;i<AS.lives;i++){
        ctx.save();ctx.translate(W-14-i*20,10);ctx.rotate(-Math.PI/2);
        ctx.strokeStyle='#f57c00';ctx.lineWidth=1.5;
        ctx.beginPath();ctx.moveTo(6,0);ctx.lineTo(-4,4);ctx.lineTo(-2,0);ctx.lineTo(-4,-4);ctx.closePath();ctx.stroke();
        ctx.restore();
    }
    if(!AS.run&&!AS.over)drawWelcome('Asteroids','\u2190\u2192 rotate  \u2191 thrust  SPACE shoot');
    drawParticles();
    if(AS.over)drawGameOver(AS.score,AS.newHi);
}
// Asteroids touch controls
['asl','asu','ass','asr'].forEach(function(id){
    var el=document.getElementById('cs404-'+id);
    if(!el)return;
    function dn(e){
        e.preventDefault();
        if(id==='asl')asKeys.left=true;
        else if(id==='asr')asKeys.right=true;
        else if(id==='asu')asKeys.up=true;
        else{asKeys.shoot=true;if(!AS.run&&!AS.over)asReset();else if(AS.over)asReset();}
    }
    function up(){
        if(id==='asl')asKeys.left=false;
        else if(id==='asr')asKeys.right=false;
        else if(id==='asu')asKeys.up=false;
        else asKeys.shoot=false;
    }
    el.addEventListener('touchstart',dn,{passive:false});
    el.addEventListener('touchend',up);
    el.addEventListener('mousedown',dn);
    el.addEventListener('mouseup',up);
});

/* ═══════════════════════════════════════════════
   GAME 6 — SNAKE
   ═══════════════════════════════════════════════ */
var SN_CELL=20,SN_COLS=31,SN_ROWS=14;
var snKeys={up:false,dn:false,lt:false,rt:false};
var SN={run:false,over:false,score:0,fr:0,tick:8,dir:{x:1,y:0},nxt:{x:1,y:0},seg:[],apple:{x:15,y:7},newHi:false};
function snPlace(){
    var ok=false,ax,ay,i;
    while(!ok){
        ax=1+Math.floor(Math.random()*(SN_COLS-2));
        ay=1+Math.floor(Math.random()*(SN_ROWS-2));
        ok=true;
        for(i=0;i<SN.seg.length;i++){if(SN.seg[i].x===ax&&SN.seg[i].y===ay){ok=false;break;}}
    }
    SN.apple={x:ax,y:ay};
}
function snReset(){
    SN.dir={x:1,y:0};SN.nxt={x:1,y:0};
    var mx=Math.floor(SN_COLS/2),my=Math.floor(SN_ROWS/2);
    SN.seg=[{x:mx,y:my},{x:mx-1,y:my},{x:mx-2,y:my}];
    SN.score=0;SN.fr=0;SN.tick=8;SN.newHi=false;
    namePending=false;particles=[];
    if(nameOverlay)nameOverlay.style.display='none';
    snPlace();SN.run=true;SN.over=false;
}
function snUpdate(){
    if(!SN.run||SN.over)return;
    SN.fr++;if(SN.fr<SN.tick)return;SN.fr=0;
    // queue direction, block 180 reversal
    var nd=SN.nxt;
    if(!(nd.x===-SN.dir.x&&nd.y===-SN.dir.y))SN.dir={x:nd.x,y:nd.y};
    var hd=SN.seg[0],nx=hd.x+SN.dir.x,ny=hd.y+SN.dir.y,i;
    if(nx<0||nx>=SN_COLS||ny<0||ny>=SN_ROWS){snDie();return;}
    for(i=0;i<SN.seg.length;i++){if(SN.seg[i].x===nx&&SN.seg[i].y===ny){snDie();return;}}
    SN.seg.unshift({x:nx,y:ny});
    if(nx===SN.apple.x&&ny===SN.apple.y){
        SN.score+=10;
        if(SN.score%50===0&&SN.tick>3)SN.tick--;
        snPlace();
    } else {SN.seg.pop();}
}
function snDraw(){
    ctx.fillStyle='rgba(8,20,40,0.96)';ctx.fillRect(0,0,W,H);
    // subtle grid
    ctx.fillStyle='rgba(42,96,144,0.15)';
    for(var gx=0;gx<SN_COLS;gx++)for(var gy=0;gy<SN_ROWS;gy++){ctx.beginPath();ctx.arc(gx*SN_CELL+SN_CELL/2,gy*SN_CELL+SN_CELL/2,1,0,Math.PI*2);ctx.fill();}
    // apple
    ctx.fillStyle='#ff3a3a';ctx.beginPath();ctx.arc(SN.apple.x*SN_CELL+SN_CELL/2,SN.apple.y*SN_CELL+SN_CELL/2,SN_CELL/2-2,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#55bb33';ctx.fillRect(SN.apple.x*SN_CELL+SN_CELL/2,SN.apple.y*SN_CELL+1,3,5);
    // snake body
    for(var i=SN.seg.length-1;i>=0;i--){
        var s=SN.seg[i],t=i/Math.max(SN.seg.length-1,1);
        ctx.fillStyle=i===0?'#44ff88':('rgba('+(54+Math.round(t*60))+','+(160-Math.round(t*60))+',80,1)');
        ctx.beginPath();ctx.roundRect(s.x*SN_CELL+2,s.y*SN_CELL+2,SN_CELL-4,SN_CELL-4,3);ctx.fill();
    }
    // eyes on head
    if(SN.seg.length>0){
        var h=SN.seg[0];
        var dx=SN.dir.x,dy=SN.dir.y;
        var cx2=h.x*SN_CELL+SN_CELL/2,cy2=h.y*SN_CELL+SN_CELL/2;
        var perp={x:-dy,y:dx};
        ctx.fillStyle='#111';
        ctx.beginPath();ctx.arc(cx2+dx*4+perp.x*3,cy2+dy*4+perp.y*3,2,0,Math.PI*2);ctx.fill();
        ctx.beginPath();ctx.arc(cx2+dx*4-perp.x*3,cy2+dy*4-perp.y*3,2,0,Math.PI*2);ctx.fill();
    }
    drawHiPanel('snake');drawScore(SN.score);drawParticles();
    if(!SN.run&&!SN.over)drawWelcome('Snake','Arrow keys or d-pad \u2022 eat apples');
    if(SN.over)drawGameOver(SN.score,SN.newHi);
}
function snDie(){SN.run=false;SN.over=true;SN.newHi=checkNewHi('snake',SN.score);}
// Shared 4-directional d-pad for Snake
(function(){
    var map={'4up':{x:0,y:-1},'4dn':{x:0,y:1},'4lt':{x:-1,y:0},'4rt':{x:1,y:0}};
    Object.keys(map).forEach(function(id){
        var el=document.getElementById('cs404-'+id);if(!el)return;
        var dir=map[id];
        function press(e){
            e.preventDefault();
            if(currentGame==='snake'){if(!SN.run&&!SN.over)snReset();else if(SN.over)snReset();else SN.nxt={x:dir.x,y:dir.y};}
        }
        el.addEventListener('touchstart',press,{passive:false});el.addEventListener('mousedown',press);
    });
})();

/* =================================================
   GAME 7 — MR. DO!
   Grid-digging arcade: dig dirt, collect cherries,
   throw your ball, dodge monsters, drop apples.
   ================================================= */
var MD_CELL=20,MD_COLS=31,MD_ROWS=14;
// cell values: 0=open 1=dirt 2=cherry 3=apple
var MR={
    grid:[],px:0,py:0,
    pDir:{x:0,y:0},pNxt:{x:0,y:0},
    score:0,lives:3,run:false,over:false,win:false,newHi:false,
    ball:{x:0,y:0,dx:0,dy:0,on:false,bounces:0},
    monsters:[],cherries:0,total:0,frame:0
};
function mdG(r,c){if(r<0||r>=MD_ROWS||c<0||c>=MD_COLS)return -1;return MR.grid[r][c];}
function mdS(r,c,v){if(r>=0&&r<MD_ROWS&&c>=0&&c<MD_COLS)MR.grid[r][c]=v;}
function mdCX(c){return c*MD_CELL+MD_CELL/2;}
function mdCY(r){return r*MD_CELL+MD_CELL/2;}
function mdRC(px,py){return{r:Math.round((py-MD_CELL/2)/MD_CELL),c:Math.round((px-MD_CELL/2)/MD_CELL)};}
function mdCanEnter(r,c){var v=mdG(r,c);return v===0||v===1||v===2;}
function mdMonCan(r,c){var v=mdG(r,c);return v===0||v===2;}
function mdReset(){
    MR.grid=[];
    for(var r=0;r<MD_ROWS;r++){
        MR.grid[r]=[];
        for(var c=0;c<MD_COLS;c++){
            MR.grid[r][c]=(r===0||r===MD_ROWS-1||c===0||c===MD_COLS-1)?0:1;
        }
    }
    [3,7,11].forEach(function(r){for(var c=1;c<MD_COLS-1;c++)MR.grid[r][c]=0;});
    [7,15,23].forEach(function(vc){for(var r=1;r<MD_ROWS-1;r++)MR.grid[r][vc]=0;});
    MR.total=0;
    [[1,2,1,6],[1,2,8,14],[1,2,16,22],[1,2,24,29],
     [4,6,1,6],[4,6,8,14],[4,6,16,22],[4,6,24,29],
     [8,10,1,6],[8,10,8,14],[8,10,16,22],[8,10,24,29]].forEach(function(q){
        for(var r=q[0];r<=q[1];r++){for(var c=q[2];c<=q[3];c+=3){
            if(MR.grid[r][c]===1){MR.grid[r][c]=2;MR.total++;}
        }}
    });
    [[3,3],[3,11],[3,19],[3,27],[7,3],[7,27],[11,3],[11,11],[11,19],[11,27]].forEach(function(p){
        if(MR.grid[p[0]][p[1]]===0)MR.grid[p[0]][p[1]]=3;
    });
    MR.px=mdCX(15);MR.py=mdCY(7);
    MR.pDir={x:0,y:0};MR.pNxt={x:0,y:0};
    MR.ball={x:MR.px,y:MR.py,dx:0,dy:0,on:false,bounces:0};
    MR.monsters=[
        {x:mdCX(1), y:mdCY(3), dx:1, dy:0,dead:false,flashT:0},
        {x:mdCX(29),y:mdCY(3), dx:-1,dy:0,dead:false,flashT:0},
        {x:mdCX(1), y:mdCY(11),dx:1, dy:0,dead:false,flashT:0},
        {x:mdCX(29),y:mdCY(11),dx:-1,dy:0,dead:false,flashT:0},
    ];
    MR.score=0;MR.lives=3;MR.over=false;MR.win=false;MR.newHi=false;MR.cherries=0;MR.frame=0;
    MR.run=true;
}
function mdThrow(){
    if(!MR.run||MR.ball.on)return;
    var dx=MR.pDir.x,dy=MR.pDir.y;
    if(dx===0&&dy===0){dx=1;}
    MR.ball={x:MR.px,y:MR.py,dx:dx,dy:dy,on:true,bounces:0};
}
function mdUpdate(){
    if(!MR.run||MR.over||MR.win)return;
    MR.frame++;
    var pSpd=1.5;
    var prc=mdRC(MR.px,MR.py);
    var atCX=Math.abs(MR.px-mdCX(prc.c))<pSpd+0.5;
    var atCY=Math.abs(MR.py-mdCY(prc.r))<pSpd+0.5;
    if(atCX&&atCY){
        MR.px=mdCX(prc.c);MR.py=mdCY(prc.r);
        var nd=MR.pNxt;
        if(mdCanEnter(prc.r+nd.y,prc.c+nd.x))MR.pDir={x:nd.x,y:nd.y};
        var nr=prc.r+MR.pDir.y,nc=prc.c+MR.pDir.x;
        if(mdCanEnter(nr,nc)){
            if(mdG(nr,nc)===1)mdS(nr,nc,0);
            MR.px+=MR.pDir.x*pSpd;MR.py+=MR.pDir.y*pSpd;
        }
    } else {
        MR.px+=MR.pDir.x*pSpd;MR.py+=MR.pDir.y*pSpd;
    }
    MR.px=Math.max(MD_CELL/2,Math.min((MD_COLS-1)*MD_CELL+MD_CELL/2,MR.px));
    MR.py=Math.max(MD_CELL/2,Math.min((MD_ROWS-1)*MD_CELL+MD_CELL/2,MR.py));
    var prc2=mdRC(MR.px,MR.py);
    if(mdG(prc2.r,prc2.c)===2){
        mdS(prc2.r,prc2.c,0);MR.cherries++;MR.score+=100;
        if(MR.cherries>=MR.total){MR.win=true;MR.run=false;MR.newHi=checkNewHi('mrdo',MR.score);return;}
    }
    if(MR.ball.on){
        var bSpd=3.5;
        MR.ball.x+=MR.ball.dx*bSpd;MR.ball.y+=MR.ball.dy*bSpd;
        var bounced=false;
        if(MR.ball.x<=MD_CELL/2){MR.ball.x=MD_CELL/2;MR.ball.dx*=-1;bounced=true;}
        if(MR.ball.x>=(MD_COLS-1)*MD_CELL+MD_CELL/2){MR.ball.x=(MD_COLS-1)*MD_CELL+MD_CELL/2;MR.ball.dx*=-1;bounced=true;}
        if(MR.ball.y<=MD_CELL/2){MR.ball.y=MD_CELL/2;MR.ball.dy*=-1;bounced=true;}
        if(MR.ball.y>=(MD_ROWS-1)*MD_CELL+MD_CELL/2){MR.ball.y=(MD_ROWS-1)*MD_CELL+MD_CELL/2;MR.ball.dy*=-1;bounced=true;}
        if(bounced)MR.ball.bounces++;
        MR.monsters.forEach(function(m){
            if(m.dead)return;
            if(Math.hypot(m.x-MR.ball.x,m.y-MR.ball.y)<MD_CELL){
                m.dead=true;m.flashT=50;MR.score+=500;MR.ball.on=false;
                if(MR.monsters.every(function(m2){return m2.dead;})){MR.win=true;MR.run=false;MR.newHi=checkNewHi('mrdo',MR.score);}
            }
        });
        if(MR.ball.bounces>=2)MR.ball.on=false;
        if(Math.hypot(MR.ball.x-MR.px,MR.ball.y-MR.py)<MD_CELL*0.7)MR.ball.on=false;
    }
    if(MR.frame%6===0){
        for(var r=MD_ROWS-2;r>=1;r--){
            for(var c=1;c<MD_COLS-1;c++){
                if(mdG(r,c)===3&&mdG(r+1,c)===0){
                    mdS(r,c,0);mdS(r+1,c,3);
                    var ax=mdCX(c),ay=mdCY(r+1);
                    if(Math.abs(MR.px-ax)<MD_CELL*0.7&&Math.abs(MR.py-ay)<MD_CELL*0.7){
                        MR.lives--;
                        if(MR.lives<=0){MR.over=true;MR.run=false;MR.newHi=checkNewHi('mrdo',MR.score);}
                        else{MR.px=mdCX(15);MR.py=mdCY(7);MR.pDir={x:1,y:0};MR.pNxt={x:1,y:0};}
                    }
                    MR.monsters.forEach(function(m){
                        if(!m.dead&&Math.abs(m.x-ax)<MD_CELL*0.7&&Math.abs(m.y-ay)<MD_CELL*0.7){
                            m.dead=true;m.flashT=50;MR.score+=1000;
                        }
                    });
                }
            }
        }
    }
    if(MR.frame%2===0){
        MR.monsters.forEach(function(m){
            if(m.dead){if(m.flashT>0)m.flashT--;return;}
            var mrc=mdRC(m.x,m.y);
            var atMX=Math.abs(m.x-mdCX(mrc.c))<1.6;
            var atMY=Math.abs(m.y-mdCY(mrc.r))<1.6;
            if(atMX&&atMY){
                m.x=mdCX(mrc.c);m.y=mdCY(mrc.r);
                var pr=mdRC(MR.px,MR.py);
                var dirs=[{x:m.dx,y:m.dy},{x:0,y:1},{x:0,y:-1},{x:1,y:0},{x:-1,y:0}];
                dirs.sort(function(a,b){
                    var da=(mrc.r+a.y-pr.r)*(mrc.r+a.y-pr.r)+(mrc.c+a.x-pr.c)*(mrc.c+a.x-pr.c);
                    var db=(mrc.r+b.y-pr.r)*(mrc.r+b.y-pr.r)+(mrc.c+b.x-pr.c)*(mrc.c+b.x-pr.c);
                    return da-db;
                });
                var moved=false;
                for(var i=0;i<dirs.length;i++){
                    var d=dirs[i];if(d.x===0&&d.y===0)continue;
                    if(d.x===-m.dx&&d.y===-m.dy)continue;
                    if(mdMonCan(mrc.r+d.y,mrc.c+d.x)){m.dx=d.x;m.dy=d.y;moved=true;break;}
                }
                if(!moved){m.dx*=-1;m.dy*=-1;}
            }
            m.x+=m.dx;m.y+=m.dy;
            m.x=Math.max(MD_CELL/2,Math.min((MD_COLS-1)*MD_CELL+MD_CELL/2,m.x));
            m.y=Math.max(MD_CELL/2,Math.min((MD_ROWS-1)*MD_CELL+MD_CELL/2,m.y));
            if(!m.dead&&Math.hypot(MR.px-m.x,MR.py-m.y)<MD_CELL*0.75){
                MR.lives--;
                if(MR.lives<=0){MR.over=true;MR.run=false;MR.newHi=checkNewHi('mrdo',MR.score);}
                else{MR.px=mdCX(15);MR.py=mdCY(7);MR.pDir={x:1,y:0};MR.pNxt={x:1,y:0};}
            }
        });
    }
}
function mdDraw(){
    ctx.fillStyle='#0a150a';ctx.fillRect(0,0,W,H);
    if(!MR.grid.length){drawWelcome('Mr. Do!','Arrows/d-pad to dig \u2022 Space to throw ball');return;}
    for(var r=0;r<MD_ROWS;r++){
        for(var c=0;c<MD_COLS;c++){
            var v=mdG(r,c),x=c*MD_CELL,y=r*MD_CELL;
            if(v===1){
                ctx.fillStyle='#4a8c20';ctx.fillRect(x,y,MD_CELL,MD_CELL);
                ctx.fillStyle='#3a7018';
                ctx.fillRect(x+3,y+4,3,2);ctx.fillRect(x+11,y+10,3,2);ctx.fillRect(x+7,y+7,2,2);
            } else if(v===2){
                ctx.fillStyle='#dd2222';
                ctx.beginPath();ctx.arc(x+MD_CELL/2-2,y+MD_CELL/2+1,4,0,Math.PI*2);ctx.fill();
                ctx.beginPath();ctx.arc(x+MD_CELL/2+3,y+MD_CELL/2+1,4,0,Math.PI*2);ctx.fill();
                ctx.strokeStyle='#228822';ctx.lineWidth=1.5;
                ctx.beginPath();ctx.moveTo(x+MD_CELL/2-2,y+MD_CELL/2-3);ctx.lineTo(x+MD_CELL/2+1,y+4);ctx.stroke();
                ctx.beginPath();ctx.moveTo(x+MD_CELL/2+3,y+MD_CELL/2-3);ctx.lineTo(x+MD_CELL/2+1,y+4);ctx.stroke();
            } else if(v===3){
                ctx.fillStyle='#cc3300';ctx.fillRect(x+3,y+3,MD_CELL-6,MD_CELL-6);
                ctx.fillStyle='#ff6622';ctx.fillRect(x+5,y+5,MD_CELL-10,MD_CELL-10);
                ctx.fillStyle='#fff';ctx.font='9px sans-serif';ctx.textAlign='center';
                ctx.fillText('A',x+MD_CELL/2,y+MD_CELL/2+3);
            }
        }
    }
    if(MR.ball.on){
        ctx.fillStyle='#ffffff';ctx.beginPath();ctx.arc(MR.ball.x,MR.ball.y,5,0,Math.PI*2);ctx.fill();
        ctx.strokeStyle='#aaaaff';ctx.lineWidth=1;ctx.beginPath();ctx.arc(MR.ball.x,MR.ball.y,5,0,Math.PI*2);ctx.stroke();
    }
    MR.monsters.forEach(function(m){
        if(m.dead){
            if(m.flashT>0&&Math.floor(m.flashT/6)%2===0){
                ctx.font='16px sans-serif';ctx.textAlign='center';ctx.fillText('\uD83D\uDCA5',m.x,m.y+6);
            }
            return;
        }
        ctx.fillStyle='#cc1111';
        ctx.beginPath();ctx.arc(m.x,m.y,MD_CELL/2-3,0,Math.PI*2);ctx.fill();
        ctx.fillStyle='#ff6666';ctx.beginPath();ctx.arc(m.x,m.y-2,MD_CELL/2-6,Math.PI,0);ctx.fill();
        ctx.fillStyle='#fff';
        ctx.beginPath();ctx.arc(m.x-3,m.y-3,2.5,0,Math.PI*2);ctx.fill();
        ctx.beginPath();ctx.arc(m.x+3,m.y-3,2.5,0,Math.PI*2);ctx.fill();
        ctx.fillStyle='#000';
        ctx.beginPath();ctx.arc(m.x-3+m.dx,m.y-3+m.dy,1.2,0,Math.PI*2);ctx.fill();
        ctx.beginPath();ctx.arc(m.x+3+m.dx,m.y-3+m.dy,1.2,0,Math.PI*2);ctx.fill();
    });
    ctx.fillStyle='#0055cc';
    ctx.beginPath();ctx.arc(MR.px,MR.py,MD_CELL/2-2,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#ffe0b2';ctx.beginPath();ctx.arc(MR.px,MR.py-2,5,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#ff3300';ctx.beginPath();ctx.arc(MR.px,MR.py-1,2,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#333';
    ctx.beginPath();ctx.arc(MR.px-2,MR.py-4,1.2,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(MR.px+2,MR.py-4,1.2,0,Math.PI*2);ctx.fill();
    ctx.font='bold 11px monospace';ctx.fillStyle='#ff4444';ctx.textAlign='left';
    ctx.fillText('\u2665'.repeat(MR.lives),8,13);
    ctx.fillStyle='#fff';ctx.textAlign='right';ctx.fillText(String(MR.score).padStart(6,'0'),W-8,13);
    var lb=lbData['mrdo'];if(lb&&lb.length>0){ctx.fillStyle='#ffcc00';ctx.textAlign='center';ctx.font='9px monospace';ctx.fillText('\uD83C\uDFC6 '+(lb[0].n||'Anonymous')+' '+lb[0].s,W/2,13);}
    ctx.fillStyle='#dd2222';ctx.font='10px monospace';ctx.textAlign='left';
    ctx.fillText('\uD83C\uDF52 '+MR.cherries+'/'+MR.total,8,H-4);
    ctx.fillStyle='#aaa';ctx.font='9px monospace';ctx.textAlign='right';
    ctx.fillText(MR.ball.on?'':'[SPACE] throw',W-8,H-4);
    drawParticles();
    if(!MR.run&&!MR.over&&!MR.win)drawWelcome('Mr. Do!','Arrows/d-pad to dig \u2022 Space to throw ball');
    if(MR.win){
        ctx.fillStyle='rgba(0,0,0,0.75)';ctx.fillRect(0,0,W,H);
        ctx.textAlign='center';ctx.fillStyle='#ffff00';ctx.font='bold 18px monospace';ctx.fillText('LEVEL CLEAR!',W/2,H/2-12);
        ctx.fillStyle='#fff';ctx.font='12px monospace';ctx.fillText('Score: '+MR.score,W/2,H/2+8);
        ctx.fillStyle='#f57c00';ctx.font='bold 12px monospace';ctx.fillText('SPACE or TAP to play again',W/2,H/2+28);
    }
    if(MR.over)drawGameOver(MR.score,MR.newHi);
}


/* ═══════════════════════════════════════════════
   GAME 7 — SPACE INVADERS
   ═══════════════════════════════════════════════ */
var SI_COLS=10,SI_ROWS=4,SI_IW=26,SI_IH=16,SI_IX=10,SI_IY=8;
var SI_PW=30,SI_PH=12,SI_PY=H-20;
var SI_CS=(W-SI_COLS*(SI_IW+SI_IX)+SI_IX)/2;
var SI={run:false,over:false,score:0,newHi:false,lives:3,
    px:0,bullet:null,iBullets:[],invaders:[],dir:1,
    spd:0.8,fr:0,shootTimer:0,wave:0};
var siKeys={left:false,right:false};

function siReset(){
    SI.run=true;SI.over=false;SI.newHi=false;
    if(!SI.wave){SI.lives=3;SI.score=0;}
    SI.px=W/2-SI_PW/2;SI.bullet=null;SI.iBullets=[];
    SI.fr=0;SI.shootTimer=0;SI.dir=1;
    SI.spd=0.8+SI.wave*0.25;
    SI.invaders=[];
    for(var r=0;r<SI_ROWS;r++){
        for(var cc=0;cc<SI_COLS;cc++){
            SI.invaders.push({x:SI_CS+cc*(SI_IW+SI_IX),y:28+r*(SI_IH+SI_IY),alive:true,row:r,col:cc});
        }
    }
}
function siShoot(){
    if(!SI.run||SI.bullet)return;
    SI.bullet={x:SI.px+SI_PW/2,y:SI_PY-4};
}
function siUpdate(){
    if(!SI.run)return;
    SI.fr++;
    var alive=SI.invaders.filter(function(i){return i.alive;});
    if(!alive.length){SI.wave++;siReset();return;}
    // Move invaders
    var step=SI.spd*(1+0.015*(SI_COLS*SI_ROWS-alive.length));
    alive.forEach(function(i){i.x+=step*SI.dir;});
    var minX=Math.min.apply(null,alive.map(function(i){return i.x;}));
    var maxX=Math.max.apply(null,alive.map(function(i){return i.x+SI_IW;}));
    if(maxX>W-2||minX<2){
        SI.dir*=-1;alive.forEach(function(i){i.y+=10;});
        SI.spd=Math.min(SI.spd+0.1,5);
    }
    if(alive.some(function(i){return i.y+SI_IH>=SI_PY;})){
        SI.lives=0;SI.over=true;SI.run=false;SI.wave=0;
        SI.newHi=checkNewHi('spaceinvaders',SI.score);return;
    }
    // Player bullet
    if(SI.bullet){
        SI.bullet.y-=7;
        if(SI.bullet.y<0){SI.bullet=null;}
        else{
            for(var i=0;i<SI.invaders.length;i++){
                var inv=SI.invaders[i];if(!inv.alive)continue;
                if(SI.bullet.x>=inv.x&&SI.bullet.x<=inv.x+SI_IW&&SI.bullet.y>=inv.y&&SI.bullet.y<=inv.y+SI_IH){
                    inv.alive=false;
                    var pts=[30,20,15,10];SI.score+=(pts[inv.row]||10);SI.bullet=null;
                    var ic=['#c084fc','#60a5fa','#34d399','#fbbf24'];
                    for(var p=0;p<7;p++){var pa=Math.random()*Math.PI*2,ps=1.5+Math.random()*2.5;
                        particles.push({x:inv.x+SI_IW/2,y:inv.y+SI_IH/2,vx:Math.cos(pa)*ps,vy:Math.sin(pa)*ps,life:1,dec:0.04+Math.random()*0.04,r:2+Math.random()*2,col:ic[inv.row]||'#fff'});}
                    break;
                }
            }
        }
    }
    // Invader shooting
    SI.shootTimer++;
    var rate=Math.max(16,72-alive.length*1.5);
    if(SI.shootTimer>=rate){
        SI.shootTimer=0;
        var front={};
        alive.forEach(function(i){if(!front[i.col]||i.y>front[i.col].y)front[i.col]=i;});
        var shooters=Object.values(front);
        var s=shooters[Math.floor(Math.random()*shooters.length)];
        SI.iBullets.push({x:s.x+SI_IW/2,y:s.y+SI_IH});
    }
    // Invader bullets
    SI.iBullets=SI.iBullets.filter(function(b){
        b.y+=3.5;
        if(b.y>H)return false;
        if(b.x>SI.px&&b.x<SI.px+SI_PW&&b.y>SI_PY){
            SI.lives--;
            for(var p=0;p<6;p++){var pa=Math.random()*Math.PI*2,ps=1+Math.random()*2;
                particles.push({x:b.x,y:SI_PY+SI_PH/2,vx:Math.cos(pa)*ps,vy:Math.sin(pa)*ps,life:1,dec:0.06,r:2+Math.random()*2,col:'#f87171'});}
            if(SI.lives<=0){SI.over=true;SI.run=false;SI.wave=0;SI.newHi=checkNewHi('spaceinvaders',SI.score);}
            return false;
        }
        return true;
    });
    // Player movement (keyboard + on-screen buttons)
    if(keysDown['ArrowLeft']||keysDown['KeyA']||siKeys.left)SI.px=Math.max(0,SI.px-3.5);
    if(keysDown['ArrowRight']||keysDown['KeyD']||siKeys.right)SI.px=Math.min(W-SI_PW,SI.px+3.5);
}
function siDraw(){
    ctx.clearRect(0,0,W,H);
    var bg=ctx.createLinearGradient(0,0,0,H);bg.addColorStop(0,'#020617');bg.addColorStop(1,'#0f172a');
    ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);
    ctx.fillStyle='rgba(255,255,255,0.55)';
    for(var s=0;s<40;s++){ctx.fillRect((s*83+SI.fr*0.04)%W,(s*47)%H,1,1);}
    if(!SI.run&&!SI.over){drawWelcome('Space Invaders','\u2190\u2192 move  \u2022  Space to fire');return;}
    // Invaders
    var ic=['#c084fc','#60a5fa','#34d399','#fbbf24'];
    SI.invaders.forEach(function(inv){
        if(!inv.alive)return;
        var col=ic[inv.row]||'#fff';var anim=Math.floor(SI.fr/14)%2;
        ctx.fillStyle=col;
        ctx.beginPath();ctx.roundRect(inv.x+2,inv.y+1,SI_IW-4,SI_IH-3,3);ctx.fill();
        ctx.fillStyle='rgba(0,0,0,0.7)';
        ctx.fillRect(inv.x+5,inv.y+4,4,4);ctx.fillRect(inv.x+SI_IW-9,inv.y+4,4,4);
        ctx.fillStyle=col;
        if(anim===0){ctx.fillRect(inv.x+3,inv.y+SI_IH-2,3,3);ctx.fillRect(inv.x+SI_IW-6,inv.y+SI_IH-2,3,3);}
        else{ctx.fillRect(inv.x+6,inv.y+SI_IH-1,3,4);ctx.fillRect(inv.x+SI_IW-9,inv.y+SI_IH-1,3,4);}
    });
    // Ground line
    ctx.fillStyle='rgba(34,211,238,0.25)';ctx.fillRect(0,SI_PY+SI_PH+3,W,1);
    // Player ship
    ctx.fillStyle='#22d3ee';
    ctx.beginPath();ctx.roundRect(SI.px,SI_PY,SI_PW,SI_PH,4);ctx.fill();
    ctx.fillRect(SI.px+SI_PW/2-2,SI_PY-6,4,7);
    ctx.fillStyle='rgba(255,255,255,0.4)';
    ctx.beginPath();ctx.roundRect(SI.px+SI_PW/2-5,SI_PY+2,10,4,2);ctx.fill();
    // Player bullet
    if(SI.bullet){
        ctx.shadowBlur=6;ctx.shadowColor='#fbbf24';
        ctx.fillStyle='#fbbf24';ctx.fillRect(SI.bullet.x-1,SI.bullet.y,3,10);
        ctx.shadowBlur=0;
    }
    // Invader bullets
    ctx.fillStyle='#f87171';
    SI.iBullets.forEach(function(b){ctx.fillRect(b.x-1,b.y,3,8);});
    // Lives
    ctx.font='bold 11px monospace';ctx.textAlign='left';ctx.fillStyle='#22d3ee';
    var ls='';for(var l=0;l<SI.lives;l++)ls+='\u2665 ';
    ctx.fillText(ls.trim(),8,H-5);
    drawHiPanel('spaceinvaders');drawScore(SI.score);drawParticles();
    if(SI.over)drawGameOver(SI.score,SI.newHi);
}

/* ── Input ──────────────────────────────────────── */
var keysDown={};
document.addEventListener('keydown',function(e){
    if(e.target&&e.target.tagName==='INPUT')return;
    keysDown[e.code]=true;
    if(currentGame==='miner'){
        if(e.code==='ArrowLeft'||e.code==='KeyA')mmKeys.left=true;
        if(e.code==='ArrowRight'||e.code==='KeyD')mmKeys.right=true;
        if(e.code==='ArrowUp'||e.code==='KeyW'||e.code==='Space')mmKeys.jump=true;
        if(e.code==='Space'){
            var el=document.getElementById('cs404-game');
            if(el){var r=el.getBoundingClientRect();if(r.top<window.innerHeight&&r.bottom>0)e.preventDefault();}
            if(!MM.run&&!MM.over)mmReset();else if(MM.over)mmReset();
        }
        return;
    }
    if(currentGame==='asteroids'){
        if(e.code==='ArrowLeft'||e.code==='KeyA')asKeys.left=true;
        if(e.code==='ArrowRight'||e.code==='KeyD')asKeys.right=true;
        if(e.code==='ArrowUp'||e.code==='KeyW')asKeys.up=true;
        if(e.code==='Space'){
            var el=document.getElementById('cs404-game');
            if(el){var r=el.getBoundingClientRect();if(r.top<window.innerHeight&&r.bottom>0)e.preventDefault();}
            asKeys.shoot=true;
            if(!AS.run&&!AS.over)asReset();else if(AS.over)asReset();
        }
        return;
    }
    if(currentGame==='snake'){
        if(e.code==='ArrowUp'||e.code==='KeyW'){e.preventDefault();SN.nxt={x:0,y:-1};}
        else if(e.code==='ArrowDown'||e.code==='KeyS'){e.preventDefault();SN.nxt={x:0,y:1};}
        else if(e.code==='ArrowLeft'||e.code==='KeyA'){e.preventDefault();SN.nxt={x:-1,y:0};}
        else if(e.code==='ArrowRight'||e.code==='KeyD'){e.preventDefault();SN.nxt={x:1,y:0};}
        if(e.code==='Space'){if(!SN.run&&!SN.over)snReset();else if(SN.over)snReset();}
        return;
    }
    if(currentGame==='spaceinvaders'){
        if(e.code==='Space'||e.key===' '){
            var el=document.getElementById('cs404-game');
            if(el){var r=el.getBoundingClientRect();if(r.top<window.innerHeight&&r.bottom>0)e.preventDefault();}
            if(!SI.run&&!SI.over){siReset();}else if(SI.over){SI.wave=0;siReset();}else{siShoot();}
            return;
        }
    }
    if(e.code==='Space'||e.key===' '){
        var el=document.getElementById('cs404-game');
        if(el){var r=el.getBoundingClientRect();if(r.top<window.innerHeight&&r.bottom>0){e.preventDefault();onAction();}}
    }
    if(currentGame==='racer'){
        if(e.key==='ArrowLeft'||e.code==='KeyA')rcMove('l');
        if(e.key==='ArrowRight'||e.code==='KeyD')rcMove('r');
    }
});
document.addEventListener('keyup',function(e){
    keysDown[e.code]=false;
    if(e.code==='ArrowLeft'||e.code==='KeyA'){mmKeys.left=false;asKeys.left=false;}
    if(e.code==='ArrowRight'||e.code==='KeyD'){mmKeys.right=false;asKeys.right=false;}
    if(e.code==='ArrowUp'||e.code==='KeyW'){mmKeys.jump=false;asKeys.up=false;}
    if(e.code==='Space'){mmKeys.jump=false;asKeys.shoot=false;}
});
c.addEventListener('click',function(e){
    if(currentGame==='racer'){
        var r=c.getBoundingClientRect(),cx=e.clientX-r.left;
        if(cx<W/2)rcMove('l');else rcMove('r');
    } else if(currentGame==='miner'){
        if(!MM.run&&!MM.over)mmReset();else if(MM.over)mmReset();
    } else if(currentGame==='asteroids'){
        if(!AS.run&&!AS.over)asReset();else if(AS.over)asReset();
    } else if(currentGame==='spaceinvaders'){
        if(!SI.run&&!SI.over)siReset();else if(SI.over){SI.wave=0;siReset();}else siShoot();
    } else onAction();
});
document.addEventListener('touchstart',function(e){
    var t=e.target;
    if(t.tagName==='A'||t.tagName==='BUTTON'||t.tagName==='INPUT')return;
    if(t!==c)return; // only intercept taps directly on the canvas
    if(currentGame==='racer'){
        var r=c.getBoundingClientRect(),cx=e.touches[0].clientX-r.left;
        e.preventDefault();if(cx<W/2)rcMove('l');else rcMove('r');
    } else if(currentGame==='miner'){
        /* handled by miner buttons above */
    } else if(currentGame==='spaceinvaders'){
        e.preventDefault();
        if(!SI.run&&!SI.over){siReset();}else if(SI.over){SI.wave=0;siReset();}else siShoot();
    } else {
        e.preventDefault();onAction();
    }
},{passive:false});
function onAction(){
    if(currentGame==='runner')rnJump();
    else if(currentGame==='jetpack')jpBoost();
    else if(currentGame==='racer'){if(!RC.run&&!RC.over)rcReset();else if(RC.over)rcReset();}
    else if(currentGame==='miner'){if(!MM.run&&!MM.over)mmReset();else if(MM.over)mmReset();}
    else if(currentGame==='asteroids'){if(!AS.run&&!AS.over)asReset();else if(AS.over)asReset();}
    else if(currentGame==='snake'){if(!SN.run&&!SN.over)snReset();else if(SN.over)snReset();}
    else if(currentGame==='spaceinvaders'){if(!SI.run&&!SI.over)siReset();else if(SI.over){SI.wave=0;siReset();}else siShoot();}
}

/* ── Space Invaders on-screen buttons ───────────── */
(function(){
    function press(key,val){return function(e){e.preventDefault();
        if(!SI.run&&!SI.over){siReset();return;}
        if(SI.over){SI.wave=0;siReset();return;}
        if(key==='fire'){siShoot();}else siKeys[key]=val;
    };}
    ['sil','sir','sif'].forEach(function(id){
        var el=document.getElementById('cs404-'+id);if(!el)return;
        var key=id==='sil'?'left':id==='sir'?'right':'fire';
        el.addEventListener('mousedown',press(key,true));
        el.addEventListener('touchstart',press(key,true),{passive:false});
        if(key!=='fire'){
            el.addEventListener('mouseup',function(){siKeys[key]=false;});
            el.addEventListener('mouseleave',function(){siKeys[key]=false;});
            el.addEventListener('touchend',function(){siKeys[key]=false;});
            el.addEventListener('touchcancel',function(){siKeys[key]=false;});
        }
    });
})();

/* ── Tab switching ──────────────────────────────── */
var mcCtrl=document.getElementById('cs404-miner-ctrl');
var asCtrl=document.getElementById('cs404-asteroids-ctrl');
var siCtrl=document.getElementById('cs404-si-ctrl');
var d4Ctrl=document.getElementById('cs404-4dir-ctrl');
document.querySelectorAll('.cs404-tab').forEach(function(tab){
    tab.addEventListener('click',function(){
        currentGame=tab.getAttribute('data-game');
        document.querySelectorAll('.cs404-tab').forEach(function(t){t.classList.remove('active');});
        tab.classList.add('active');
        particles=[];namePending=false;
        if(nameOverlay)nameOverlay.style.display='none';
        mmKeys.left=false;mmKeys.right=false;mmKeys.jump=false;
        asKeys.left=false;asKeys.right=false;asKeys.up=false;asKeys.shoot=false;
        siKeys.left=false;siKeys.right=false;
        if(mcCtrl)mcCtrl.style.display=currentGame==='miner'?'flex':'none';
        if(asCtrl)asCtrl.style.display=currentGame==='asteroids'?'flex':'none';
        if(siCtrl)siCtrl.style.display=currentGame==='spaceinvaders'?'flex':'none';
        if(d4Ctrl)d4Ctrl.style.display=currentGame==='snake'?'grid':'none';
        renderLeaderboard(currentGame);
    });
});

/* ── Main loop ──────────────────────────────────── */
function loop(){
    if(currentGame==='runner'){rnUpdate();rnDraw();}
    else if(currentGame==='jetpack'){jpUpdate();jpDraw();}
    else if(currentGame==='racer'){rcUpdate();rcDraw();}
    else if(currentGame==='miner'){mmUpdate();mmDraw();}
    else if(currentGame==='asteroids'){asUpdate();asDraw();}
    else if(currentGame==='snake'){snUpdate();snDraw();}
    else if(currentGame==='spaceinvaders'){siUpdate();siDraw();}
    updateParticles();
    requestAnimationFrame(loop);
}
renderLeaderboard(currentGame);
loop();
})();
