async function s(e,a){let t=await fetch(e,a);return t.status===401&&(await new Promise(i=>setTimeout(i,800)),t=await fetch(e,a)),t}export{s as f};
