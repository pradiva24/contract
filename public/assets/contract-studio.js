(function(){
'use strict';
const cfg=window.CONTRACT_STUDIO_CONFIG||{};
if(!window.Konva){alert('Canvas library could not be loaded. Check your internet connection.');return;}
const A4={portrait:{width:794,height:1123,label:'210 × 297 mm'},landscape:{width:1123,height:794,label:'297 × 210 mm'}};
let doc=cfg.initialDocument||{schema_version:1,orientation:'portrait',pages:[{id:'page-1',background:'#ffffff',objects:[]}]};
if(!Array.isArray(doc.pages)||!doc.pages.length)doc.pages=[{id:'page-1',background:'#fff',objects:[]}];
let pageIndex=0,scale=.7,stage,layer,transformer,selected=null,undoStack=[],historyIndex=-1,dirty=false;
const $=id=>document.getElementById(id);
const page=()=>doc.pages[pageIndex];
const dims=()=>A4[doc.orientation||'portrait'];
function snapshot(){const s=JSON.stringify(doc);if(undoStack[historyIndex]===s)return;undoStack=undoStack.slice(0,historyIndex+1);undoStack.push(s);if(undoStack.length>80)undoStack.shift();historyIndex=undoStack.length-1;dirty=true;updateSaveState();updateHistoryButtons();}
function updateSaveState(){const el=$('saveState');if(el)el.textContent=dirty?'Unsaved changes':'All changes saved';}
function updateHistoryButtons(){$('undoBtn').disabled=historyIndex<=0;$('redoBtn').disabled=historyIndex>=undoStack.length-1;}
function objectData(node){return node.getAttr('studioData')||{};}
function setSelected(node){selected=node;transformer.nodes(node?[node]:[]);refreshProperties();renderLayers();}
function createStage(){
 const d=dims();$('canvasMount').innerHTML='';$('canvasMount').style.width=(d.width*scale)+'px';$('canvasMount').style.height=(d.height*scale)+'px';
 stage=new Konva.Stage({container:'canvasMount',width:d.width*scale,height:d.height*scale});
 layer=new Konva.Layer();layer.scale({x:scale,y:scale});stage.add(layer);
 const bg=new Konva.Rect({x:0,y:0,width:d.width,height:d.height,fill:page().background||'#fff',listening:false});layer.add(bg);bg.moveToBottom();
 transformer=new Konva.Transformer({rotateEnabled:true,keepRatio:false,anchorSize:8,borderStroke:'#2563eb',anchorStroke:'#2563eb',anchorFill:'#fff'});layer.add(transformer);
 (page().objects||[]).forEach(addNodeFromData);
 stage.on('click tap',e=>{if(e.target===stage||e.target===bg){setSelected(null);return;}let n=e.target;while(n&&n.getParent()!==layer)n=n.getParent();setSelected(n&&n!==transformer?n:null);});
 stage.on('dragend transformend',()=>{syncSelected();snapshot();});
 layer.draw();updateDimensions();renderPageList();renderLayers();
}
function addNodeFromData(o,onAdded){
 let n;
 if(o.type==='text'){n=new Konva.Text({x:o.x||60,y:o.y||60,width:o.width||500,text:o.text||'Text',fontSize:o.fontSize||32,fontFamily:o.fontFamily||'Arial',fontStyle:o.fontStyle||'normal',fill:o.fill||'#111111',align:o.align||'left',draggable:!o.locked});}
 else if(o.type==='rect'){n=new Konva.Rect({x:o.x||60,y:o.y||60,width:o.width||260,height:o.height||120,fill:o.fill||'#286900',cornerRadius:o.cornerRadius||0,draggable:!o.locked});}
 else if(o.type==='image'&&o.src){const img=new Image();img.onload=()=>{const imageNode=new Konva.Image({x:o.x||60,y:o.y||60,width:o.width||240,height:o.height||160,image:img,draggable:!o.locked});imageNode.setAttr('studioData',o);layer.add(imageNode);layer.draw();if(onAdded)onAdded(imageNode);};img.src=o.src;return;}
 else return;
 n.setAttr('studioData',o);layer.add(n);if(onAdded)onAdded(n);
}
function addObject(o){page().objects.push(o);addNodeFromData(o,n=>{layer.draw();setSelected(n);});layer.draw();snapshot();}
function addText(text='Double-click to edit text',size=34){addObject({id:'obj-'+Date.now(),type:'text',name:'Text',text,x:80,y:100,width:540,fontSize:size,fill:'#111111'});}
function addRect(){addObject({id:'obj-'+Date.now(),type:'rect',name:'Shape',x:100,y:140,width:280,height:110,fill:'#286900',cornerRadius:4});}
function refreshProperties(){
 const empty=!selected;$('emptyProperties').hidden=!empty;$('objectProperties').hidden=empty;if(empty)return;
 const d=objectData(selected);$('propName').value=d.name||d.type||'Object';$('propText').value=selected instanceof Konva.Text?selected.text():'';$('propFontSize').value=selected.fontSize?selected.fontSize():24;
 $('propColor').value=selected.fill&&typeof selected.fill()==='string'&&selected.fill().startsWith('#')?selected.fill():'#111111';
 $('propFill').value=selected.fill&&typeof selected.fill()==='string'&&selected.fill().startsWith('#')?selected.fill():'#286900';
 $('propWidth').value=Math.round(selected.width()*selected.scaleX());$('propHeight').value=Math.round(selected.height()*selected.scaleY());$('propX').value=Math.round(selected.x());$('propY').value=Math.round(selected.y());
}
function syncSelected(){
 if(!selected)return;const d=objectData(selected);d.x=Math.round(selected.x());d.y=Math.round(selected.y());d.width=Math.round(selected.width()*selected.scaleX());d.height=Math.round(selected.height()*selected.scaleY());selected.scaleX(1);selected.scaleY(1);
 if(selected instanceof Konva.Text){d.text=selected.text();d.fontSize=selected.fontSize();d.fill=selected.fill();}
 else if(selected instanceof Konva.Rect)d.fill=selected.fill();
 d.name=$('propName').value||d.name;selected.setAttr('studioData',d);refreshProperties();renderLayers();layer.draw();
}
function updateProp(id,fn){if(!selected)return;fn();syncSelected();snapshot();}
function renderLayers(){const el=$('layerList');if(!el)return;el.innerHTML='';[...(page().objects||[])].reverse().forEach(o=>{const b=document.createElement('button');b.className='layer-entry'+(selected&&objectData(selected).id===o.id?' active':'');b.type='button';b.textContent=o.name||o.type;b.onclick=()=>{const n=layer.getChildren().find(x=>objectData(x).id===o.id);if(n)setSelected(n);};el.appendChild(b);});}
function renderPageList(){const el=$('pageList');el.innerHTML='';doc.pages.forEach((p,i)=>{const b=document.createElement('button');b.className='page-entry'+(i===pageIndex?' active':'');b.type='button';b.textContent='Page '+(i+1);b.onclick=()=>{pageIndex=i;setSelected(null);createStage();};el.appendChild(b);});}
function updateDimensions(){const d=dims();$('pageDimensions').textContent='A4 · '+d.label;$('orientation').value=doc.orientation||'portrait';}
function setScale(v){scale=Math.max(.25,Math.min(1.5,v));createStage();$('zoomValue').textContent=Math.round(scale*100)+'%';}
function uploadImage(file){if(!file)return;if(file.size>3*1024*1024){alert('Image limit is 5 MB.');return;}const reader=new FileReader();reader.onload=()=>{const src=reader.result;const img=new Image();img.onload=()=>{const max=420,ratio=Math.min(max/img.width,max/img.height,1);addObject({id:'obj-'+Date.now(),type:'image',name:file.name,x:80,y:100,width:img.width*ratio,height:img.height*ratio,src});};img.src=src;};reader.readAsDataURL(file);}
$('addText').onclick=()=>addText();$('sideText').onclick=()=>addText();$('addHeading').onclick=()=>addText('OFFICIAL CONTRACT RATE 2026',42);
$('addRect').onclick=addRect;$('sideShape').onclick=addRect;$('addImage').onclick=()=>$('imageInput').click();$('sideImage').onclick=()=>$('imageInput').click();$('imageInput').onchange=e=>uploadImage(e.target.files[0]);
$('addProductTable').onclick=()=>addText('PRODUCT                 PUBLISH RATE                 CONTRACT RATE\nBALI SWING ACTIVE       IDR 300K                     IDR 100K\nENTRANCE PACKAGE        IDR 50K                      IDR 25K',18);
$('addTerms').onclick=()=>addText('TERMS & CONDITIONS\nCancellation policy: ...',20);$('addFooter').onclick=()=>addText('Marketing · Reservation · WhatsApp · Email',16);
$('deleteObject').onclick=()=>{if(!selected)return;const id=objectData(selected).id;page().objects=page().objects.filter(o=>o.id!==id);selected.destroy();setSelected(null);layer.draw();snapshot();};
$('orientation').onchange=e=>{doc.orientation=e.target.value;setScale(scale);snapshot();};
$('addPage').onclick=()=>{doc.pages.push({id:'page-'+Date.now(),background:'#ffffff',objects:[]});pageIndex=doc.pages.length-1;setSelected(null);createStage();snapshot();};
$('zoomIn').onclick=()=>setScale(scale+.1);$('zoomOut').onclick=()=>setScale(scale-.1);$('fitPage').onclick=()=>setScale(.7);
$('propName').onchange=()=>updateProp('name',()=>{const d=objectData(selected);d.name=$('propName').value;selected.setAttr('studioData',d);});
$('propText').onchange=()=>updateProp('text',()=>{if(selected instanceof Konva.Text)selected.text($('propText').value);});
$('propFontSize').onchange=()=>updateProp('fontSize',()=>{if(selected instanceof Konva.Text)selected.fontSize(Number($('propFontSize').value)||24);});
$('propColor').onchange=()=>updateProp('color',()=>{if(selected instanceof Konva.Text)selected.fill($('propColor').value);});
$('propFill').onchange=()=>updateProp('fill',()=>{if(selected.fill)selected.fill($('propFill').value);});
['propWidth','propHeight','propX','propY'].forEach(id=>$(id).onchange=()=>updateProp(id,()=>{const v=Number($(id).value)||0;if(id==='propWidth')selected.width(Math.max(10,v));if(id==='propHeight')selected.height(Math.max(10,v));if(id==='propX')selected.x(v);if(id==='propY')selected.y(v);}));
$('bringForward').onclick=()=>{if(selected){selected.moveUp();transformer.moveToTop();layer.draw();snapshot();renderLayers();}};
$('sendBackward').onclick=()=>{if(selected){selected.moveDown();transformer.moveToTop();layer.draw();snapshot();renderLayers();}};
$('lockObject').onclick=()=>{if(selected){const d=objectData(selected);d.locked=!d.locked;selected.draggable(!d.locked);selected.setAttr('studioData',d);snapshot();}};
$('undoBtn').onclick=()=>{if(historyIndex>0){historyIndex--;doc=JSON.parse(undoStack[historyIndex]);createStage();dirty=true;updateSaveState();updateHistoryButtons();}};
$('redoBtn').onclick=()=>{if(historyIndex<undoStack.length-1){historyIndex++;doc=JSON.parse(undoStack[historyIndex]);createStage();dirty=true;updateSaveState();updateHistoryButtons();}};
$('saveBtn').onclick=async()=>{const btn=$('saveBtn');btn.disabled=true;syncAll();const body=new URLSearchParams({_csrf:cfg.csrf,action:'save_template',id:String(cfg.templateId||0),name:$('templateName').value,document_json:JSON.stringify(doc)});try{const res=await fetch(cfg.saveUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const data=await res.json();if(!data.ok)throw new Error(data.message||'Save failed');cfg.templateId=data.id;const url=new URL(location.href);url.searchParams.set('id',data.id);window.history.replaceState({},'',url);dirty=false;updateSaveState();$('saveState').textContent='Saved · v'+data.version;}catch(err){alert(err.message);}finally{btn.disabled=false;}};
function syncAll(){layer.getChildren().forEach(n=>{const d=objectData(n);if(!d.id)return;d.x=Math.round(n.x());d.y=Math.round(n.y());d.width=Math.round(n.width()*n.scaleX());d.height=Math.round(n.height()*n.scaleY());if(n instanceof Konva.Text){d.text=n.text();d.fontSize=n.fontSize();d.fill=n.fill();}else if(n instanceof Konva.Rect)d.fill=n.fill();n.setAttr('studioData',d);});}
window.addEventListener('keydown',e=>{if((e.key==='Delete'||e.key==='Backspace')&&selected&&document.activeElement.tagName!=='INPUT'&&document.activeElement.tagName!=='TEXTAREA')$('deleteObject').click();if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();$('saveBtn').click();}});
snapshot();dirty=false;updateSaveState();createStage();$('zoomValue').textContent=Math.round(scale*100)+'%';
})();