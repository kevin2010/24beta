<?php
class PostController extends Controller
{
    public function filters()
    {
        return array(
            'ajaxOnly + visit, digg',
            'postOnly + visit, digg',
        );
    }
    
    public function actionDetail($id)
    {
        $this->redirect(aurl('post/show', array('id'=>$id)), true, 301);
    }
    
    public function actionShow($id)
    {
        $this->autoSwitchMobile();
        
        $id = (int)$id;
        if ($id <= 0)
            throw new CHttpException(404, t('post_is_not_found'));
        
        $cacheID = sprintf(param('cache_post_id'), $id);
        $cachePostId = app()->cache->get($cacheID);
        if ($cachePostId === false && !user()->checkAccess('enter_admin_system'))
            $post = Post::model()->published()->findByPk($id);
        else
            $post = Post::model()->findByPk($id);
        
        if (null === $post)
            throw new CHttpException(403, t('post_is_not_found'));

        $comments = Comment::model()->fetchList($id);
        $hotComments = Comment::model()->fetchHotList($id);
        $comment = new CommentForm();
        $comment->post_id = $id;
        
        $this->channel = $post->category_id;
        $this->setSiteTitle($post->title);
        $this->setPageDescription($post->summary);
        $this->setPageKeyWords($post->tagText);
        
        if ($post->getContainCode()) {
            cs()->registerScriptFile(sbu('libs/prettify/prettify.js'), CClientScript::POS_END);
            cs()->registerCssFile(sbu('libs/prettify/prettify.css'), 'screen');
        }
        
        cs()->registerMetaTag('all', 'robots');
        $this->render('show', array(
            'post' => $post,
            'comment' => $comment,
            'comments' => $comments,
            'hotComments' => $hotComments,
        ));
    }
    
    public function actionVisit($callback)
    {
        $callback = strip_tags(trim($callback));
        $id = (int)$_POST['id'];
        if ($id <= 0)
            throw new CHttpException(500);
        
        $post = Post::model()->findByPk($id);
        if (null === $post)
            throw new CHttpException(404, t('post_is_not_found'));
        $post->visit_nums += 1;
        $post->update(array('visit_nums'));
        BetaBase::jsonp($callback, $post->visit_nums);
    }
    
    public function actionComment($callback, $id = 0)
    {
        $id = (int)$id;
        $callback = strip_tags(trim($callback));
        
        if (!request()->getIsAjaxRequest() || !request()->getIsPostRequest() || empty($callback))
            throw new CHttpException(500);
        
        $data = array();
        $model = new CommentForm();
        $model->attributes = $_POST['CommentForm'];
        $model->content = h($model->content);
        
        if ($id > 0 && $quote = Comment::model()->findByPk($id)) {
            $quoteTitle = sprintf(t('comment_quote_title'), $quote->authorName);
            $html = '<fieldset class="beta-comment-quote"><legend>' . $quoteTitle . '</legend>' . $quote->content . '</fieldset>';
            $model->content = $html . $model->content;
        }
        
        if ($model->validate() && ($comment = $model->save())) {
            $data['errno'] = 0;
            $data['text'] = t('ajax_comment_done');
            $data['html'] = $this->renderPartial('/comment/_one', array('comment'=>$comment), true); // @todo 反回此条评论的html代码
        }
        else {
            $data['errno'] = 1;
            $attributes = array_keys($model->getErrors());
            foreach ($attributes as $attribute)
                $labels[] = $model->getAttributeLabel($attribute);
            $errstr = join(' ', $labels);
            $data['text'] = sprintf(t('ajax_comment_error'), $errstr);
        }
        echo $callback . '(' . json_encode($data) . ')';
        exit(0);
    }
    
    public function actionCreate()
    {
        $this->channel = 'contribute';
        
        $form = new PostForm();
        if (request()->getIsPostRequest() && isset($_POST['PostForm'])) {
            $form->attributes = $_POST['PostForm'];
            if ($form->validate()) {
                $post = $form->save();
                if (!$post->hasErrors()) {
                    user()->setFlash('success_post_id', $post->id);
                    $this->redirect(url('post/success'));
                    exit(0);
                }
            }
        }
        else {
            $key = param('sess_post_create_token');
            if (!app()->session->contains($key) || empty(app()->session[$key]))
                app()->session->add($key, uniqid('beta', true));
            else {
                $token = app()->session[$key];
                $tempPictures = Upload::model()->findAllByAttributes(array('token'=>$token));
            }
        }

        $captchaWidget = $form->hasErrors('captcha') ? $this->widget('BetaCaptcha', array(), true) : $this->widget('BetaCaptcha', array('skin'=>'defaultLazy'), true);
        $captchaClass = $form->hasErrors('captcha') ? 'error' : 'hide';
        
        $this->setSiteTitle(t('contribute_post'));
        
        cs()->registerMetaTag('noindex, follow', 'robots');
        $this->render('create', array(
            'form' => $form,
            'captchaClass' => $captchaClass,
            'captchaWidget' => $captchaWidget,
            'tempPictures' => $tempPictures,
        ));
    }
    
    public function actionSuccess()
    {
        $this->channel = 'contribute';
        
        $postid = user()->getFlash('success_post_id');
        if (empty($postid))
            $this->redirect(app()->homeUrl);
        
        $cacheID = sprintf(param('cache_post_id'), $postid);
        app()->cache->set($cacheID, $postid, param('expire_after_create_post_successs_post_id'));
        
        $this->setSiteTitle(t('contribute_post_success'));
        
        cs()->registerMetaTag('noindex, follow', 'robots');
        $this->render('create_success', array(
            'title'=>t('contribute_post_success'),
            'postid'=>$postid,
        ));
    }
    
    public function actionDigg()
    {
        $id = (int)$_POST['pid'];
        if ($id < 0) throw new CHttpException(500);
        
        $model = Post::model()->published()->findByPk($id);
        if ($model === null) throw new CHttpException(500);
        
        $model->digg_nums += 1;
        $result = $model->save(true, array('digg_nums'));
        
        $data = array('digg_nums'=>$model->digg_nums);
        $data['errno'] = (int)$result;
        
        echo CJSON::encode($data);
        exit(0);
    }
    
    public function actionLike()
    {
        if (user()->getIsGuest())
            $data['errno'] = -1;
        else {
            $id = (int)$_POST['pid'];
            if ($id < 0) throw new CHttpException(500);
            
            $model = Post::model()->published()->findByPk($id);
            if ($model === null) throw new CHttpException(500);
            
            $userID = (int)user()->id;
            $count = app()->getDb()->createCommand()
                ->select('count(*)')
                ->from(TABLE_POST_FAVORITE)
                ->where(array('and', 'post_id = :postid', 'user_id = :userid'), array(':postid'=>$id, ':userid'=>$userID))
                ->queryScalar();

            if ($count == 0) {
                $columns = array(
                    'post_id' => $id,
                    'user_id' => $userID,
                    'create_time' => time(),
                    'create_ip' => BetaBase::getClientIp(),
                );
                $result = app()->getDb()->createCommand()
                    ->insert(TABLE_POST_FAVORITE, $columns);
            }
            else {
                $result = app()->getDb()->createCommand()
                    ->delete(TABLE_POST_FAVORITE, array('and', 'post_id = :postid', 'user_id = :userid'), array(':postid'=>$id, ':userid'=>$userID));
            }
            if ($result > 0)
                $model->favorite_nums += ($count == 0) ? 1: -1;
            else
                throw new CHttpException(500, 'add favorite error');
            
            
            if ($model->favorite_nums < 0)
                $model->favorite_nums = 0;
            
            $result = $model->save(true, array('favorite_nums'));
            
            $data = array('favorite_nums'=>$model->favorite_nums);
            $data['errno'] = (int)$result;
        }
        echo CJSON::encode($data);
        exit(0);
    }

}


