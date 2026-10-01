# Team Rules

1. Never push directly to `main` or `dev`.
2. Create your own branch from `dev`, named like `feature/login` or `fix/gpa-bug`.
3. When your work is ready, open a Pull Request into `dev`. Someone else
   reviews and approves it before it's merged.
4. Before you start working each day: `git checkout dev && git pull`
5. Write clear commit messages, e.g. "Add GPA calculation".
6. Never commit `config.php`, passwords or real student data.
7. Use PDO prepared statements for all SQL. Hash passwords with
   `password_hash()`.
8. Tell the leader before changing the database structure.
9. If you're stuck, ask early.

```bash
git checkout dev
git pull
git checkout -b feature/login
# ...work...
git add .
git commit -m "Add login endpoint"
git push -u origin feature/login
```
Then open the Pull Request on GitHub.