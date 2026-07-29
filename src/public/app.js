// Minimal frontend logic (MVP). Uses fetch to call server API endpoints.
// Persists UI state (tabs, last opened) in localStorage.

const state = {
  username: null, repo: null, branch: null, patGlobal: false, pat: null,
  explorer: [], tabs: [], activeTab: null
};

function $(id){return document.getElementById(id)}

function saveSetupToServer(creds){
  return fetch('../server/api.php?action=save_credentials', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(creds)
  }).then(r=>r.json());
}

function pullRepo(){
  if(!state.repo){alert('Set up a repo first'); return}
  setRepoInfo('Pulling...');
  fetch(`../server/api.php?action=pull_repo`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({owner: state.username, repo: state.repo, branch: state.branch})
  }).then(r=>r.json()).then(res=>{
    if(res.ok){ loadExplorer(); setRepoInfo(`${state.repo} @ ${state.branch}`) }
    else alert('Pull failed: '+(res.error || JSON.stringify(res)))
  })
}

function setRepoInfo(text){ $('repo-info').textContent = text }

function loadExplorer(){
  fetch(`../server/api.php?action=list_files&owner=${encodeURIComponent(state.username)}&repo=${encodeURIComponent(state.repo)}&branch=${encodeURIComponent(state.branch)}`)
    .then(r=>r.json()).then(res=>{
      if(res.ok){ state.explorer = res.files; renderExplorer() }
      else console.error(res)
    })
}

function renderExplorer(){
  const el = $('file-explorer'); el.innerHTML = '';
  // render files and folders as a simple list
  (state.explorer||[]).sort((a,b)=>a.path.localeCompare(b.path)).forEach(item=>{
    const div = document.createElement('div');
    div.className = 'explorer-item';
    const chk = document.createElement('input'); chk.type='checkbox'; chk.className='sel';
    div.appendChild(chk);
    const btn = document.createElement('button');
    btn.style.background='transparent'; btn.style.border='none'; btn.style.color='inherit';
    btn.textContent = item.path;
    btn.addEventListener('click', ()=>openFile(item.path));
    div.appendChild(btn);
    el.appendChild(div);
  })
}

function openFile(path){
  // open in a tab: if exists activate, else fetch file then create tab
  const existing = state.tabs.find(t=>t.path===path);
  if(existing){ activateTab(existing.id); return }
  fetch(`../server/api.php?action=get_file&owner=${encodeURIComponent(state.username)}&repo=${encodeURIComponent(state.repo)}&branch=${encodeURIComponent(state.branch)}&path=${encodeURIComponent(path)}`)
    .then(r=>r.json()).then(res=>{
      if(!res.ok){ alert('Error getting file'); return }
      const id = 't'+Date.now();
      const tab = {id, path, content:res.content, dirty:false, scroll:0};
      state.tabs.push(tab); renderTabs(); activateTab(id); persistState();
    })
}

function renderTabs(){
  const tbar = $('tabs'); tbar.innerHTML='';
  state.tabs.forEach(t=>{
    const d = document.createElement('div'); d.className='tab'+(state.activeTab===t.id?' active':'');
    d.textContent = t.path;
    d.addEventListener('click', ()=>activateTab(t.id));
    const close = document.createElement('button'); close.textContent='✕'; close.style.marginLeft='6px';
    close.addEventListener('click',(e)=>{ e.stopPropagation(); closeTab(t.id) });
    d.appendChild(close);
    tbar.appendChild(d);
  })
  renderActiveEditor();
}

function activateTab(id){
  state.activeTab = id; renderTabs(); persistState();
}

function renderActiveEditor(){
  const area = $('editor-area'); area.innerHTML = '';
  if(!state.activeTab) return;
  const t = state.tabs.find(x=>x.id===state.activeTab); if(!t) return;
  const wrap = document.createElement('div'); wrap.className='editor-wrap';
  const ta = document.createElement('textarea');
  ta.value = t.content || '';
  ta.addEventListener('input', ()=>{ t.content = ta.value; t.dirty = true; persistTabAutosave(t) });
  // restore scroll position if saved
  ta.addEventListener('scroll', ()=> t.scroll = ta.scrollTop);
  wrap.appendChild(ta);

  const actions = document.createElement('div');
  const saveBtn = document.createElement('button'); saveBtn.textContent='Save'; saveBtn.addEventListener('click', ()=>saveFile(t));
  const saveRemote = document.createElement('button'); saveRemote.textContent='Save & Push'; saveRemote.addEventListener('click', ()=>saveFile(t, true));
  actions.appendChild(saveBtn); actions.appendChild(saveRemote);
  wrap.appendChild(actions);

  area.appendChild(wrap);

  // restore autosaved content if any
  const saved = localStorage.getItem('autosave:'+t.path);
  if(saved) ta.value = saved;
}

function persistTabAutosave(tab){
  localStorage.setItem('autosave:'+tab.path, tab.content);
  localStorage.setItem('openTabs', JSON.stringify(state.tabs.map(t=>({path:t.path,id:t.id}))));
  localStorage.setItem('activeTab', state.activeTab||'');
}

function persistState(){
  localStorage.setItem('gittacle.state', JSON.stringify({
    username: state.username, repo: state.repo, branch: state.branch,
    tabs: state.tabs.map(t=>({path:t.path,id:t.id})), activeTab: state.activeTab
  }));
}

function restoreState(){
  const s = JSON.parse(localStorage.getItem('gittacle.state')||'{}');
  if(s.username) { state.username=s.username; state.repo=s.repo; state.branch=s.branch; setRepoInfo(`${state.repo} @ ${state.branch}`) }
  if(s.tabs) {
    // restore minimal tabs and attempt to re-open
    s.tabs.forEach(t => {
      const saved = localStorage.getItem('autosave:'+t.path);
      if(saved){
        const id = t.id;
        state.tabs.push({id, path: t.path, content: saved, dirty:false, scroll:0});
      }
    });
    state.activeTab = s.activeTab;
    renderTabs();
  }
}

function saveFile(tab, push=false){
  const body = { owner: state.username, repo: state.repo, branch: state.branch, path: tab.path, content: tab.content, message: `Update ${tab.path}` };
  fetch('../server/api.php?action=save_file', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  }).then(r=>r.json()).then(res=>{
    if(res.ok){ tab.dirty=false; alert('Saved to server'); if(push) pushFileToGitHub(tab) }
    else alert('Save failed: '+(res.error||JSON.stringify(res)))
  })
}

function pushFileToGitHub(tab){
  const body = { owner: state.username, repo: state.repo, branch: state.branch, path: tab.path, content: tab.content, message: `Push ${tab.path}` };
  fetch('../server/api.php?action=push_file', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  }).then(r=>r.json()).then(res=>{
    if(res.ok) alert('Pushed to GitHub')
    else alert('Push failed: '+(res.error||JSON.stringify(res)))
  })
}

function closeTab(id){
  state.tabs = state.tabs.filter(t=>t.id!==id);
  if(state.activeTab===id) state.activeTab = state.tabs.length?state.tabs[0].id:null;
  renderTabs(); persistState();
}

function setupBindings(){
  $('btn-setup').addEventListener('click', ()=>$('modal-setup').classList.remove('hidden'));
  $('btn-cancel-setup').addEventListener('click', ()=>$('modal-setup').classList.add('hidden'));
  $('btn-save-setup').addEventListener('click', ()=>{
    const username = $('input-username').value.trim();
    const repo = $('input-repo').value.trim();
    const branch = $('input-branch').value.trim()||'main';
    const pat = $('input-pat').value.trim();
    const global = $('input-global').checked;
    if(!username||!repo||!pat){ alert('username, repo and PAT required'); return }
    state.username = username; state.repo = repo; state.branch = branch; state.pat = pat; state.patGlobal = global;
    saveSetupToServer({username, repo, branch, pat, global}).then(res=>{
      if(res.ok){ $('modal-setup').classList.add('hidden'); setRepoInfo(`${repo} @ ${branch}`); pullRepo() }
      else alert('Save failed: '+(res.error||JSON.stringify(res)))
    })
  });

  $('btn-pull').addEventListener('click', pullRepo);
  $('btn-download').addEventListener('click', ()=> {
    if(!state.repo) return alert('No repo set'); window.location.href = `../server/api.php?action=download_zip&owner=${encodeURIComponent(state.username)}&repo=${encodeURIComponent(state.repo)}&branch=${encodeURIComponent(state.branch)}`;
  });

  $('btn-new-file').addEventListener('click', ()=>{
    const path = prompt('New file path (e.g. src/new.txt)');
    if(!path) return;
    fetch('../server/api.php?action=create_file', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({owner: state.username, repo: state.repo, branch: state.branch, path, content: '', message:'Create '+path})
    }).then(r=>r.json()).then(res=>{
      if(res.ok) { loadExplorer(); openFile(path) } else alert('Create failed: '+(res.error||JSON.stringify(res)))
    })
  });

  $('btn-upload').addEventListener('click', ()=>$('file-upload').click());
  $('file-upload').addEventListener('change', (e)=>{
    const files = Array.from(e.target.files);
    if(!files.length) return;
    const form = new FormData();
    form.append('owner', state.username); form.append('repo', state.repo); form.append('branch', state.branch);
    files.forEach(f=>form.append('files[]', f));
    fetch('../server/api.php?action=upload_files', {method:'POST', body: form}).then(r=>r.json()).then(res=>{
      if(res.ok){ loadExplorer(); alert('Uploaded') } else alert('Upload failed: '+JSON.stringify(res))
    })
  });

  // multi-delete button
  $('btn-multi-delete').addEventListener('click', ()=>{
    const checked = Array.from(document.querySelectorAll('#file-explorer input.sel')).filter(c=>c.checked).map(c=>c.parentElement.querySelector('button').textContent);
    if(!checked.length) return alert('No files selected');
    if(!confirm('Delete '+checked.length+' files?')) return;
    fetch('../server/api.php?action=delete_files', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({owner:state.username, repo:state.repo, branch:state.branch, paths:checked})})
      .then(r=>r.json()).then(res=>{ if(res.ok){ loadExplorer(); alert('Deleted') } else alert('Delete failed: '+JSON.stringify(res)) })
  });

  // restore UI state
  restoreState();
}

document.addEventListener('DOMContentLoaded', setupBindings);
