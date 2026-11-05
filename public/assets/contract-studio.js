(function(){
'use strict';
const cfg=window.CONTRACT_STUDIO_CONFIG||{};
if(!window.Konva){alert('Canvas library could not be loaded. Check your internet connection.');return;}
Konva.dragDistance=0;Konva.hitOnDragEnabled=true;
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
function setSelected(node){selected=node;transformer.nodes(node?[node]:[]);transformer.moveToTop();refreshProperties();renderLayers();layer.draw();}
function replaceTableNode(data){const old=selected; if(old&&old.getParent()===layer)old.destroy(); selected=null; addNodeFromData(data); const node=layer.getChildren().find(n=>objectData(n).id===data.id); if(node)setSelected(node); layer.draw();}
function createStage(){
 (page().objects||[]).forEach(o=>{if((o.type==='text'||o.type==='table')&&o.fontFamily)loadGoogleFont(o.fontFamily);});
 const d=dims();$('canvasMount').innerHTML='';$('canvasMount').style.width=(d.width*scale)+'px';$('canvasMount').style.height=(d.height*scale)+'px';
 stage=new Konva.Stage({container:'canvasMount',width:d.width*scale,height:d.height*scale});
 stage.container().style.touchAction='none';
 layer=new Konva.Layer();layer.scale({x:scale,y:scale});stage.add(layer);
 const bg=new Konva.Rect({x:0,y:0,width:d.width,height:d.height,fill:page().background||'#fff',listening:false});layer.add(bg);bg.moveToBottom();
 if(page().backgroundImage){const bgImg=new Image();bgImg.onload=()=>{const kimg=new Konva.Image({x:0,y:0,width:d.width,height:d.height,image:bgImg,listening:false,name:'page-background'});layer.add(kimg);kimg.setZIndex(1);layer.draw();};bgImg.onerror=()=>alert('Background could not be displayed. Please upload another image.');bgImg.src=page().backgroundImage;}
 transformer=new Konva.Transformer({rotateEnabled:true,keepRatio:false,anchorSize:8,borderStroke:'#2563eb',anchorStroke:'#2563eb',anchorFill:'#fff'});layer.add(transformer);
 (page().objects||[]).forEach(addNodeFromData);transformer.moveToTop();
 stage.on('click tap',e=>{if(e.target===stage||e.target===bg){setSelected(null);return;}let n=e.target;while(n&&n.getParent()!==layer)n=n.getParent();setSelected(n&&n!==transformer?n:null);});
 stage.on('dragstart',e=>{let n=e.target;while(n&&n.getParent()!==layer)n=n.getParent();if(n&&n!==bg&&n!==transformer)setSelected(n);});
 stage.on('dragend transformend',()=>{syncSelected();snapshot();});
 layer.draw();updateDimensions();renderPageList();renderLayers();
}
function editTableCell(group,cell){
 const d=objectData(group),ri=cell.getAttr('cellRow'),ci=cell.getAttr('cellCol');if(!d.rows||!d.rows[ri])return;
 const rect=$('canvasMount').getBoundingClientRect(),pos=cell.getAbsolutePosition(),area=document.createElement('textarea');
 area.value=String(d.rows[ri][ci]??'');area.className='inline-cell-editor';area.style.left=(rect.left+pos.x)+'px';area.style.top=(rect.top+pos.y)+'px';area.style.width=Math.max(70,(cell.width()-14)*scale)+'px';area.style.height=Math.max(32,(cell.height()-10)*scale)+'px';area.style.fontSize=Math.max(10,cell.fontSize()*scale)+'px';document.body.appendChild(area);area.focus();area.select();
 const finish=()=>{if(!area.isConnected)return;d.rows[ri][ci]=area.value;group.setAttr('studioData',d);area.remove();replaceTableNode(d);snapshot();};
 area.addEventListener('blur',finish);area.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();area.blur();}if(e.key==='Escape'){area.value=d.rows[ri][ci]??'';area.blur();}});
}
function changeTableDimensions(kind,delta){
 if(!selected||objectData(selected).type!=='table')return;const d=objectData(selected),rows=d.rows||[];d.columns=Math.max(1,Math.min(10,(d.columns||3)+(kind==='column'?delta:0)));
 if(kind==='row'){if(delta>0)rows.push(Array.from({length:d.columns},(_,i)=>i===0?'New item':''));else if(rows.length>1)rows.pop();}
 if(kind==='column'){rows.forEach((r,i)=>{if(delta>0)r.push(i===0?'NEW COLUMN':'');else if(r.length>1)r.pop();});}
 d.rows=rows;d.columnWidths=Array.isArray(d.columnWidths)?d.columnWidths:[];while(d.columnWidths.length<d.columns)d.columnWidths.push(1);d.columnWidths=d.columnWidths.slice(0,d.columns);d.rowHeights=Array.isArray(d.rowHeights)?d.rowHeights:[];if(kind==='row'&&delta>0)d.rowHeights.push(d.rowHeight||52);if(kind==='row'&&delta<0)d.rowHeights.pop();d.height=d.rowHeights.reduce((sum,h)=>sum+(Number(h)||d.rowHeight||52),0)||rows.length*(d.rowHeight||52);d.columnFontColors=(d.columnFontColors||[]).slice(0,d.columns);while(d.columnFontColors.length<d.columns)d.columnFontColors.push(d.bodyFontColor||'#20242b');selected.setAttr('studioData',d);const id=d.id;createStage();const node=layer.getChildren().find(n=>objectData(n).id===id);if(node)setSelected(node);snapshot();
}
function addNodeFromData(o,onAdded){
 let n;
 if(o.type==='text'){n=new Konva.Text({x:o.x||60,y:o.y||60,width:o.width||500,height:o.height||undefined,text:o.text||'Text',fontSize:o.fontSize||32,fontFamily:o.fontFamily||'Arial',fontStyle:o.fontStyle||'normal',fill:o.fill||'#111111',align:o.align||'left',wrap:o.wrap||'word',draggable:!o.locked,dragDistance:0});}
 else if(o.type==='icon'){n=new Konva.Text({x:o.x||60,y:o.y||60,width:o.width||54,height:o.height||54,text:o.glyph||'\uf00c',fontFamily:'Font Awesome 6 Free',fontStyle:'900',fontSize:o.fontSize||48,fill:o.fill||'#20242b',draggable:!o.locked,dragDistance:0});}
 else if(o.type==='rect'){n=new Konva.Rect({x:o.x||60,y:o.y||60,width:o.width||260,height:o.height||120,fill:o.fill||'#286900',cornerRadius:o.cornerRadius||0,draggable:!o.locked});}
 else if(o.type==='table'){
  const rows=Array.isArray(o.rows)?o.rows:[['Service / Product','Description','Contract Rate'],['Service name','Description','Rate']];
  const cols=Math.max(1,Math.min(10,Number(o.columns)||3)),rowH=Number(o.rowHeight)||Math.max(28,(o.height||180)/Math.max(1,rows.length)),rawWidths=Array.isArray(o.columnWidths)?o.columnWidths:[],safeWidths=Array.from({length:cols},(_,i)=>Number(rawWidths[i])>0?Number(rawWidths[i]):1),widthSum=safeWidths.reduce((sum,w)=>sum+w,0)||cols,tableWidth=Math.max(20,Number(o.width)||600),colWidths=safeWidths.map(w=>w*tableWidth/widthSum);
  const rowHeights=Array.from({length:rows.length},(_,ri)=>Math.max(20,Number((o.rowHeights||[])[ri])||rowH));let tableHeight=0;const rowOffsets=rowHeights.map(h=>{const y=tableHeight;tableHeight+=h;return y;});
  const group=new Konva.Group({x:o.x||60,y:o.y||60,width:tableWidth,height:tableHeight,draggable:!o.locked,dragDistance:0});
  rows.forEach((row,ri)=>{for(let ci=0;ci<cols;ci++){const x=colWidths.slice(0,ci).reduce((sum,w)=>sum+w,0),y=rowOffsets[ri],cellH=rowHeights[ri],colW=colWidths[ci];group.add(new Konva.Rect({x,y,width:colW,height:cellH,fill:ri===0?(o.headerFill||'#e9eef7'):(o.bodyFill||'#ffffff'),stroke:o.borderColor||'#667085',strokeWidth:Math.max(0,Math.min(8,Number(o.borderWidth??1)||0)),listening:true}));const cell=new Konva.Text({x:x+7,y:y+5,width:colW-14,height:cellH-10,text:String(row[ci]??''),fontSize:ri===0?(Number(o.headerFontSize)||15):(Number(o.bodyFontSize)||13),fontFamily:o.fontFamily||'Arial',fontStyle:ri===0?(o.headerFontStyle||'bold'):(o.bodyFontStyle||'normal'),fill:ri===0?(o.headerFontColor||'#20242b'):((o.columnFontColors||[])[ci]||o.bodyFontColor||'#20242b'),align:ri===0?(o.headerAlign||'center'):(o.bodyAlign||'center'),verticalAlign:ri===0?(o.headerVerticalAlign||'middle'):(o.bodyVerticalAlign||'middle'),wrap:'word',listening:true,name:'table-cell'});cell.setAttr('cellRow',ri);cell.setAttr('cellCol',ci);cell.on('dblclick dbltap',()=>editTableCell(group,cell));group.add(cell);}});
  n=group;
 }
 else if(o.type==='image'&&o.src){const img=new Image();img.onload=()=>{const imageNode=new Konva.Image({x:o.x||60,y:o.y||60,width:o.width||240,height:o.height||160,image:img,draggable:!o.locked});imageNode.setAttr('studioData',o);imageNode.draggable(!o.locked);imageNode.listening(true);imageNode.on('mousedown touchstart',()=>setSelected(imageNode));imageNode.on('dragstart',()=>setSelected(imageNode));layer.add(imageNode);layer.draw();if(onAdded)onAdded(imageNode);};img.src=o.src;return;}
 else return;
 n.setAttr('studioData',o);n.draggable(!o.locked);n.listening(true);n.on('mousedown touchstart',()=>setSelected(n));n.on('dragstart',()=>setSelected(n));layer.add(n);if(onAdded)onAdded(n);
}
function addObject(o){page().objects.push(o);addNodeFromData(o,n=>{layer.draw();setSelected(n);});layer.draw();snapshot();}
function addText(text='Double-click to edit text',size=34){addObject({id:'obj-'+Date.now(),type:'text',name:'Text',text,x:80,y:100,width:540,height:Math.max(60,size*2),fontSize:size,fill:'#111111',wrap:'word'});}
function addParagraph(){addObject({id:'obj-'+Date.now(),type:'text',name:'Paragraph',text:'Write your paragraph here. Text will wrap inside this box.',x:80,y:150,width:420,height:150,fontSize:18,fill:'#333333',wrap:'word'});}
const faIcons={star:'\uf005',check:'\uf00c',phone:'\uf095',envelope:'\uf0e0',location:'\uf3c5',globe:'\uf0ac',car:'\uf1b9',calendar:'\uf073',clock:'\uf017',user:'\uf007',camera:'\uf030',heart:'\uf004',plane:'\uf072',wifi:'\uf1eb',whatsapp:'\uf232'};
function addIcon(){const name=$('iconChoice').value;addObject({id:'obj-'+Date.now(),type:'icon',name:'Icon '+name,icon:name,glyph:faIcons[name]||'\uf00c',x:100,y:100,width:54,height:54,fill:'#20242b'});}
function addRect(){addObject({id:'obj-'+Date.now(),type:'rect',name:'Shape',x:100,y:140,width:280,height:110,fill:'#286900',cornerRadius:4});}
function addTable(){addObject({id:'obj-'+Date.now(),type:'table',name:'Contract rate table',x:60,y:180,width:660,height:210,columns:3,columnWidths:[1,1,1],rowHeight:52,borderWidth:1,borderColor:'#667085',headerFill:'#e9eef7',bodyFill:'#ffffff',headerFontColor:'#20242b',bodyFontColor:'#20242b',columnFontColors:['#20242b','#20242b','#20242b'],fontFamily:'Arial',headerFontSize:15,bodyFontSize:13,rows:[['SERVICE / PRODUCT','DESCRIPTION','CONTRACT RATE'],['ATV Single Ride','Single participant','IDR 000,000'],['ATV Tandem Ride','Two participants','IDR 000,000'],['Additional Service','Description','IDR 000,000']]});}
function refreshProperties(){
 const empty=!selected;$('emptyProperties').hidden=!empty;$('objectProperties').hidden=empty;$('tableProperties').hidden=empty||objectData(selected).type!=='table';$('textBoxProperties').hidden=empty||objectData(selected).type!=='text';if(empty)return;
 const d=objectData(selected);
 if(d.type==='table'){
  const target=$('tableRowTarget'),prior=target.value;target.innerHTML='<option value="all">All rows</option>'+(d.rows||[]).map((_,i)=>'<option value="'+i+'">Row '+(i+1)+'</option>').join('');target.value=prior==='all'||Number(prior)<(d.rows||[]).length?prior:'all';
  const rowIndex=target.value==='all'?-1:Number(target.value);$('tableRowHeight').value=rowIndex<0?(d.rowHeight||52):((d.rowHeights||[])[rowIndex]||d.rowHeight||52);
  const cw=$('columnWidthControls');cw.innerHTML='<div class="side-heading">COLUMN WIDTHS (PX)</div>';const widths=(d.columnWidths||[]).slice(0,d.columns||3);while(widths.length<(d.columns||3))widths.push(1);const total=widths.reduce((sum,w)=>sum+(Number(w)||1),0);widths.forEach((w,ci)=>{const label=document.createElement('label');label.textContent='Column '+(ci+1)+' width';const input=document.createElement('input');input.type='number';input.min='20';input.max=String(d.width||1200);input.step='1';input.value=Math.round((Number(w)||1)*(d.width||600)/total);input.onchange=()=>updateTableStyle('columnWidths',ci,Number(input.value));label.appendChild(input);cw.appendChild(label);});
  $('tableBorderWidth').value=d.borderWidth??1;$('tableHeaderFill').value=d.headerFill||'#e9eef7';$('tableBodyFill').value=d.bodyFill||'#ffffff';$('tableBorderColor').value=d.borderColor||'#667085';$('tableHeaderFontColor').value=d.headerFontColor||'#20242b';$('tableBodyFontColor').value=d.bodyFontColor||'#20242b';$('tableFontFamily').value=d.fontFamily||'Arial';$('tableHeaderFontSize').value=d.headerFontSize||15;$('tableBodyFontSize').value=d.bodyFontSize||13;$('tableHeaderFontStyle').value=d.headerFontStyle||'bold';$('tableBodyFontStyle').value=d.bodyFontStyle||'normal';$('tableHeaderAlign').value=d.headerAlign||'center';$('tableHeaderVerticalAlign').value=d.headerVerticalAlign||'middle';$('tableBodyAlign').value=d.bodyAlign||'center';$('tableBodyVerticalAlign').value=d.bodyVerticalAlign||'middle';
  const wrap=$('columnFontColors');wrap.innerHTML='<div class="side-heading">COLUMN FONT COLORS</div>';for(let ci=0;ci<(d.columns||3);ci++){const label=document.createElement('label');label.textContent='Column '+(ci+1)+' font color';const input=document.createElement('input');input.type='color';input.value=(d.columnFontColors||[])[ci]||d.bodyFontColor||'#20242b';input.onchange=()=>updateTableStyle('columnFontColors',ci,input.value);label.appendChild(input);wrap.appendChild(label);}
 }$('propName').value=d.name||d.type||'Object';$('propText').value=selected instanceof Konva.Text?selected.text():(d.type==='table'?(d.rows||[]).map(row=>row.join('\t')).join('\n'):'');const isText=selected instanceof Konva.Text&&d.type==='text';$('propFontFamily').disabled=!isText;$('propFontStyle').disabled=!isText;$('propFontFamily').value=d.fontFamily||'Arial';$('propFontStyle').value=d.fontStyle||'normal';$('propFontSize').value=selected.fontSize?selected.fontSize():24;
 $('propColor').value=selected.fill&&typeof selected.fill()==='string'&&selected.fill().startsWith('#')?selected.fill():'#111111';
 $('propFill').value=selected.fill&&typeof selected.fill()==='string'&&selected.fill().startsWith('#')?selected.fill():'#286900';
 $('propWidth').value=Math.round(selected.width()*selected.scaleX());$('propHeight').value=Math.round(selected.height()*selected.scaleY());$('propX').value=Math.round(selected.x());$('propY').value=Math.round(selected.y());
}
function syncSelected(){
 if(!selected)return;const d=objectData(selected);d.x=Math.round(selected.x());d.y=Math.round(selected.y());if(d.type!=='table'){d.width=Math.round(selected.width()*selected.scaleX());d.height=Math.round(selected.height()*selected.scaleY());}else{d.width=Math.round((d.width||600)*selected.scaleX());d.height=Math.round((d.height||180)*selected.scaleY());}selected.scaleX(1);selected.scaleY(1);
 if(selected instanceof Konva.Text){d.text=selected.text();d.fontSize=selected.fontSize();d.fill=selected.fill();d.height=selected.height();}
 else if(selected instanceof Konva.Rect)d.fill=selected.fill();
 d.name=$('propName').value||d.name;selected.setAttr('studioData',d);refreshProperties();renderLayers();layer.draw();
}
function updateTableStyle(key,value,extra){if(!selected||objectData(selected).type!=='table')return;const d=objectData(selected);if(key==='columnFontColors'){d.columnFontColors=d.columnFontColors||[];d.columnFontColors[value]=extra;}else if(key==='columnWidths'){let widths=(d.columnWidths||[]).slice(0,d.columns||3);while(widths.length<(d.columns||3))widths.push(1);const totalWidth=Math.max(20,Number(d.width)||600),target=Math.max(20,Math.min(totalWidth,Number(extra)||20));const otherTotal=widths.reduce((sum,w,i)=>sum+(i===value?0:(Number(w)||1)),0);const remaining=Math.max(20,totalWidth-target);widths=widths.map((w,i)=>i===value?target:(otherTotal>0?(Number(w)||1)*remaining/otherTotal:remaining/Math.max(1,widths.length-1)));d.columnWidths=widths;}else if(key==='bodyFontColor'){d.bodyFontColor=value;d.columnFontColors=(d.columnFontColors||[]).slice(0,d.columns||3);while(d.columnFontColors.length<(d.columns||3))d.columnFontColors.push(value);d.columnFontColors=d.columnFontColors.map(()=>value);}else if(key==='rowHeight'){const target=$('tableRowTarget').value;if(target==='all'){d.rowHeight=Math.max(20,Math.min(240,Number(value)||52));d.rowHeights=(d.rows||[]).map(()=>d.rowHeight);}else{const i=Number(target);d.rowHeights=d.rowHeights||[];while(d.rowHeights.length<(d.rows||[]).length)d.rowHeights.push(d.rowHeight||52);d.rowHeights[i]=Math.max(20,Math.min(240,Number(value)||52));}d.height=(d.rowHeights||[]).reduce((sum,h)=>sum+(Number(h)||52),0);}else d[key]=value;selected.setAttr('studioData',d);const id=d.id;const oldNode=selected;oldNode.destroy();selected=null;addNodeFromData(d);const node=layer.getChildren().find(n=>objectData(n).id===id);if(node)setSelected(node);layer.draw();snapshot();}
['tableRowHeight','tableBorderWidth','tableHeaderFill','tableBodyFill','tableBorderColor','tableHeaderFontColor','tableBodyFontColor','tableFontFamily','tableHeaderFontSize','tableBodyFontSize','tableHeaderFontStyle','tableBodyFontStyle','tableHeaderAlign','tableHeaderVerticalAlign','tableBodyAlign','tableBodyVerticalAlign'].forEach(id=>$(id).onchange=()=>{const map={tableRowHeight:'rowHeight',tableBorderWidth:'borderWidth',tableHeaderFill:'headerFill',tableBodyFill:'bodyFill',tableBorderColor:'borderColor',tableHeaderFontColor:'headerFontColor',tableBodyFontColor:'bodyFontColor',tableFontFamily:'fontFamily',tableHeaderFontSize:'headerFontSize',tableBodyFontSize:'bodyFontSize',tableHeaderFontStyle:'headerFontStyle',tableBodyFontStyle:'bodyFontStyle',tableHeaderAlign:'headerAlign',tableHeaderVerticalAlign:'headerVerticalAlign',tableBodyAlign:'bodyAlign',tableBodyVerticalAlign:'bodyVerticalAlign'};const stringValue=['tableFontFamily','tableBorderColor','tableHeaderFill','tableBodyFill','tableHeaderFontColor','tableBodyFontColor'].includes(id);const nextValue=stringValue?$(id).value:Number($(id).value);if(id==='tableFontFamily')loadGoogleFont(nextValue);updateTableStyle(map[id],nextValue);});
$('tableRowTarget').onchange=()=>refreshProperties();
function updateProp(id,fn){if(!selected)return;fn();syncSelected();snapshot();}
function renderLayers(){const el=$('layerList');if(!el)return;el.innerHTML='';[...(page().objects||[])].reverse().forEach(o=>{const b=document.createElement('button');b.className='layer-entry'+(selected&&objectData(selected).id===o.id?' active':'');b.type='button';b.textContent=o.name||o.type;b.onclick=()=>{const n=layer.getChildren().find(x=>objectData(x).id===o.id);if(n)setSelected(n);};el.appendChild(b);});}
function renderPageList(){const el=$('pageList');el.innerHTML='';doc.pages.forEach((p,i)=>{const b=document.createElement('button');b.className='page-entry'+(i===pageIndex?' active':'');b.type='button';b.textContent='Page '+(i+1);b.onclick=()=>{pageIndex=i;setSelected(null);createStage();};el.appendChild(b);});}
function updateDimensions(){const d=dims();$('pageDimensions').textContent='A4 · '+d.label;$('orientation').value=doc.orientation||'portrait';}
function setScale(v){scale=Math.max(.25,Math.min(1.5,v));createStage();$('zoomValue').textContent=Math.round(scale*100)+'%';}
function imageToCompactDataUrl(file,maxW=1400,maxH=1800,quality=.72){
 return new Promise((resolve,reject)=>{
  const reader=new FileReader();reader.onerror=()=>reject(new Error('Image file could not be read.'));
  reader.onload=()=>{const img=new Image();img.onerror=()=>reject(new Error('Invalid image file.'));
   img.onload=()=>{const ratio=Math.min(maxW/img.width,maxH/img.height,1);const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(img.width*ratio));canvas.height=Math.max(1,Math.round(img.height*ratio));const ctx=canvas.getContext('2d');ctx.drawImage(img,0,0,canvas.width,canvas.height);
    let data=canvas.toDataURL('image/webp',quality);if(!data.startsWith('data:image/webp'))data=canvas.toDataURL('image/jpeg',quality);resolve(data);
   };img.src=reader.result;
  };reader.readAsDataURL(file);
 });
}

async function uploadBackground(file){
 if(!file)return;
 if(!['image/png','image/jpeg','image/webp'].includes(file.type)){alert('Choose a PNG, JPG or WebP image.');return;}
 try{const data=await imageToCompactDataUrl(file,1400,1900,.68);if(data.length>1400000)throw new Error('Background masih terlalu besar. Pilih gambar dengan resolusi lebih kecil.');page().backgroundImage=data;snapshot();createStage();$('saveState').textContent='Background added · Save template to keep it';}
 catch(err){alert(err.message||'Background upload failed.');}
}
async function uploadImage(file){
 if(!file)return;
 if(!['image/png','image/jpeg','image/webp'].includes(file.type)){alert('Choose a PNG, JPG or WebP image.');return;}
 try{const src=await imageToCompactDataUrl(file,1200,1200,.72);const img=new Image();img.onload=()=>{const max=420,ratio=Math.min(max/img.width,max/img.height,1);addObject({id:'obj-'+Date.now(),type:'image',name:file.name,x:80,y:100,width:img.width*ratio,height:img.height*ratio,src});};img.onerror=()=>alert('Converted image could not be loaded.');img.src=src;}
 catch(err){alert(err.message||'Image upload failed.');}
}

$('addText').onclick=()=>addText();$('sideText').onclick=()=>addText();$('addHeading').onclick=()=>addText('OFFICIAL CONTRACT RATE 2026',42);$('addCompanyVariable').onclick=()=>{addObject({id:'obj-'+Date.now(),type:'text',name:'Company Name',text:'{{company_name}}',x:80,y:100,width:540,fontSize:32,fill:'#111111'});};
$('addRect').onclick=addRect;$('sideShape').onclick=addRect;$('addImage').onclick=()=>$('imageInput').click();$('sideImage').onclick=()=>$('imageInput').click();$('imageInput').onchange=e=>{uploadImage(e.target.files[0]);e.target.value='';};
$('addBackground').onclick=()=>$('backgroundInput').click();$('sideBackground').onclick=()=>$('backgroundInput').click();$('backgroundInput').onchange=e=>{uploadBackground(e.target.files[0]);e.target.value='';};
$('addProductTable').onclick=addTable;$('addParagraph').onclick=addParagraph;$('addIcon').onclick=addIcon;$('addTableRow').onclick=()=>changeTableDimensions('row',1);$('removeTableRow').onclick=()=>changeTableDimensions('row',-1);$('addTableColumn').onclick=()=>changeTableDimensions('column',1);$('removeTableColumn').onclick=()=>changeTableDimensions('column',-1);
$('addTerms').onclick=()=>addText('TERMS & CONDITIONS\nCancellation policy: ...',20);$('addFooter').onclick=()=>addText('Marketing · Reservation · WhatsApp · Email',16);
$('deleteObject').onclick=()=>{if(!selected)return;const id=objectData(selected).id;page().objects=page().objects.filter(o=>o.id!==id);selected.destroy();setSelected(null);layer.draw();snapshot();};
$('orientation').onchange=e=>{doc.orientation=e.target.value;setScale(scale);snapshot();};
$('addPage').onclick=()=>{doc.pages.push({id:'page-'+Date.now(),background:'#ffffff',objects:[]});pageIndex=doc.pages.length-1;setSelected(null);createStage();snapshot();};
$('zoomIn').onclick=()=>setScale(scale+.1);$('zoomOut').onclick=()=>setScale(scale-.1);$('fitPage').onclick=()=>setScale(.7);
$('propName').onchange=()=>updateProp('name',()=>{const d=objectData(selected);d.name=$('propName').value;selected.setAttr('studioData',d);});
$('propText').onchange=()=>{
 if(!selected)return;
 const d=objectData(selected);
 if(d.type==='table'){
  const rows=$('propText').value.split(/\r?\n/).map(line=>line.split('\t'));
  d.rows=rows.length?rows:[['Service / Product','Description','Contract Rate']];
  d.columns=Math.max(1,Math.min(10,...[Math.max(...d.rows.map(row=>row.length)),Number(d.columns)||1]));
  d.rows=d.rows.map(row=>Array.from({length:d.columns},(_,i)=>String(row[i]??'')));
  d.columnWidths=Array.isArray(d.columnWidths)?d.columnWidths.slice(0,d.columns):[];
  while(d.columnWidths.length<d.columns)d.columnWidths.push(1);
  d.columnFontColors=Array.isArray(d.columnFontColors)?d.columnFontColors.slice(0,d.columns):[];
  while(d.columnFontColors.length<d.columns)d.columnFontColors.push(d.bodyFontColor||'#20242b');
  d.rowHeights=Array.isArray(d.rowHeights)?d.rowHeights:[];
  d.height=d.rows.reduce((sum,_,i)=>sum+(Number(d.rowHeights[i])||Number(d.rowHeight)||52),0);
  d.width=Math.max(20,Number(d.width)||660);selected.setAttr('studioData',d);
  replaceTableNode(d);snapshot();
 }else updateProp('text',()=>{if(selected instanceof Konva.Text){selected.text($('propText').value);const d=objectData(selected);d.text=selected.text();selected.setAttr('studioData',d);}});
};
function loadGoogleFont(family){if(!family||['Arial','Georgia','Times New Roman','Tahoma','Verdana','Courier New'].includes(family))return;const id='google-font-'+family.toLowerCase().replace(/[^a-z0-9]+/g,'-');if(document.getElementById(id))return;const link=document.createElement('link');link.id=id;link.rel='stylesheet';link.href='https://fonts.googleapis.com/css2?family='+encodeURIComponent(family).replace(/%20/g,'+')+':wght@400;500;600;700;800&display=swap';document.head.appendChild(link);}
$('propFontFamily').onchange=()=>{if(!selected||!(selected instanceof Konva.Text)||objectData(selected).type!=='text')return;const d=objectData(selected),family=$('propFontFamily').value;loadGoogleFont(family);d.fontFamily=family;selected.fontFamily(family);selected.setAttr('studioData',d);layer.draw();snapshot();};
$('propFontStyle').onchange=()=>{if(!selected||!(selected instanceof Konva.Text)||objectData(selected).type!=='text')return;const d=objectData(selected),style=$('propFontStyle').value;d.fontStyle=style;selected.fontStyle(style);selected.setAttr('studioData',d);layer.draw();snapshot();};
$('propFontSize').onchange=()=>updateProp('fontSize',()=>{if(selected instanceof Konva.Text)selected.fontSize(Number($('propFontSize').value)||24);});
$('propColor').onchange=()=>updateProp('color',()=>{if(selected instanceof Konva.Text)selected.fill($('propColor').value);});
$('propFill').onchange=()=>updateProp('fill',()=>{if(selected.fill)selected.fill($('propFill').value);});
['propWidth','propHeight','propX','propY'].forEach(id=>$(id).onchange=()=>updateProp(id,()=>{const v=Number($(id).value)||0;if(id==='propWidth')selected.width(Math.max(10,v));if(id==='propHeight')selected.height(Math.max(10,v));if(id==='propX')selected.x(v);if(id==='propY')selected.y(v);}));
$('bringForward').onclick=()=>{if(selected){selected.moveUp();transformer.moveToTop();layer.draw();snapshot();renderLayers();}};
$('sendBackward').onclick=()=>{if(selected){selected.moveDown();transformer.moveToTop();layer.draw();snapshot();renderLayers();}};
$('lockObject').onclick=()=>{if(selected){const d=objectData(selected);d.locked=!d.locked;selected.draggable(!d.locked);selected.setAttr('studioData',d);snapshot();}};
$('undoBtn').onclick=()=>{if(historyIndex>0){historyIndex--;doc=JSON.parse(undoStack[historyIndex]);createStage();dirty=true;updateSaveState();updateHistoryButtons();}};
$('redoBtn').onclick=()=>{if(historyIndex<undoStack.length-1){historyIndex++;doc=JSON.parse(undoStack[historyIndex]);createStage();dirty=true;updateSaveState();updateHistoryButtons();}};
$('saveBtn').onclick=async()=>{const btn=$('saveBtn');btn.disabled=true;try{syncAll();const payload=JSON.stringify(doc);if(payload.length>6800000)throw new Error('Template masih terlalu besar ('+(payload.length/1024/1024).toFixed(2)+' MB). Kurangi jumlah gambar atau gunakan gambar lebih kecil.');const body=new URLSearchParams({_csrf:cfg.csrf,action:'save_template',id:String(cfg.templateId||0),company_id:String(cfg.companyId||0),name:$('templateName').value,document_json:payload});const res=await fetch(cfg.saveUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const raw=await res.text();let data;try{data=JSON.parse(raw);}catch(_){throw new Error('Server tidak mengirim JSON. Kemungkinan batas PHP post_max_size / upload limit terlampaui atau terjadi PHP error. HTTP '+res.status+'.');}if(!res.ok||!data.ok)throw new Error(data.message||('Save failed · HTTP '+res.status));cfg.templateId=data.id;const url=new URL(location.href);url.searchParams.set('id',data.id);window.history.replaceState({},'',url);dirty=false;updateSaveState();$('saveState').textContent='Saved · v'+data.version;}catch(err){alert(err.message);}finally{btn.disabled=false;}};
function syncAll(){layer.getChildren().forEach(n=>{const d=objectData(n);if(!d.id)return;d.x=Math.round(n.x());d.y=Math.round(n.y());if(d.type!=='table'){d.width=Math.round(n.width()*n.scaleX());d.height=Math.round(n.height()*n.scaleY());}else{d.width=Math.round((d.width||600)*n.scaleX());d.height=Math.round((d.height||180)*n.scaleY());}if(n instanceof Konva.Text){d.text=n.text();d.fontSize=n.fontSize();d.fill=n.fill();}else if(n instanceof Konva.Rect)d.fill=n.fill();n.setAttr('studioData',d);});}
window.addEventListener('keydown',e=>{if((e.key==='Delete'||e.key==='Backspace')&&selected&&document.activeElement.tagName!=='INPUT'&&document.activeElement.tagName!=='TEXTAREA')$('deleteObject').click();if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();$('saveBtn').click();}});
snapshot();dirty=false;updateSaveState();createStage();$('zoomValue').textContent=Math.round(scale*100)+'%';
})();