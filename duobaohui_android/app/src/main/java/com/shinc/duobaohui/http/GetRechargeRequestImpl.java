package com.shinc.duobaohui.http;

import android.content.Context;

import com.lidroid.xutils.exception.HttpException;
import com.lidroid.xutils.http.RequestParams;
import com.lidroid.xutils.http.ResponseInfo;
import com.lidroid.xutils.http.client.HttpRequest;
import com.shinc.duobaohui.bean.RechargeImgBean;
import com.shinc.duobaohui.event.RechargeImgEvent;
import com.shinc.duobaohui.utils.web.GsonUtil;
import com.shinc.duobaohui.utils.web.HttpSendInterFace;
import com.shinc.duobaohui.utils.web.MyHttpUtils;

import java.util.List;

import de.greenrobot.event.EventBus;

/**
 * Created by liugaopo on 15/10/6.
 * 添加收货信息 请求
 */
public class GetRechargeRequestImpl implements HttpSendInterFace {


    @Override
    public void sendPostRequest(RequestParams requestParams, String url, final Context context) {

        MyHttpUtils.getInstance().send(HttpRequest.HttpMethod.POST, url, requestParams, true, false, context, new MyHttpUtils.MyRequestCallBack() {
            RechargeImgBean bean;

            @Override
            public void onSuccess(final ResponseInfo<String> responseInfo) {
                bean = GsonUtil.json2Bean(context, responseInfo.result, RechargeImgBean.class);

                EventBus.getDefault().post(new RechargeImgEvent(bean));

            }

            @Override
            public void onFailure(HttpException e, String s) {

                EventBus.getDefault().post(new RechargeImgEvent(bean));
            }
        });

    }

    @Override
    public void sendPostRequest(RequestParams requestParams, String url, List<String> request, Context context) {
    }
}